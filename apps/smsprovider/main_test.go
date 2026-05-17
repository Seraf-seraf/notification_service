package main

import (
	"bytes"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestSendMessageAcceptedAndDelivered(t *testing.T) {
	server := newTestServer("mixed")
	response := postMessage(t, server, "key-1", "subscriber-1", nil)

	if response.Code != http.StatusAccepted {
		t.Fatalf("expected 202, got %d: %s", response.Code, response.Body.String())
	}

	var body SendResponse
	decodeBody(t, response, &body)

	record := waitForStatus(t, server, body.ProviderMessageID, statusDelivered)
	if record.MessageID != "notification-1" {
		t.Fatalf("unexpected message id: %s", record.MessageID)
	}
}

func TestIdempotencyReturnsSameProviderMessage(t *testing.T) {
	server := newTestServer("mixed")
	first := postMessage(t, server, "key-1", "subscriber-1", nil)
	second := postMessage(t, server, "key-1", "subscriber-1", nil)

	if second.Code != http.StatusOK {
		t.Fatalf("expected 200 for duplicate, got %d", second.Code)
	}

	var firstBody SendResponse
	var secondBody SendResponse
	decodeBody(t, first, &firstBody)
	decodeBody(t, second, &secondBody)

	if firstBody.ProviderMessageID != secondBody.ProviderMessageID {
		t.Fatalf("expected same provider message id, got %s and %s", firstBody.ProviderMessageID, secondBody.ProviderMessageID)
	}
	if !secondBody.Deduplicated {
		t.Fatal("expected duplicate response")
	}
}

func TestTemporaryFailureScenario(t *testing.T) {
	server := newTestServer("mixed")
	response := postMessage(t, server, "key-1", "temporary-failure-recipient", nil)

	if response.Code != http.StatusServiceUnavailable {
		t.Fatalf("expected 503, got %d", response.Code)
	}
}

func TestPermanentFailureScenario(t *testing.T) {
	server := newTestServer("mixed")
	response := postMessage(t, server, "key-1", "invalid-recipient", nil)

	if response.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422, got %d", response.Code)
	}
}

func TestAsyncPermanentFailureScenario(t *testing.T) {
	server := newTestServer("mixed")
	response := postMessage(t, server, "key-1", "subscriber-1", map[string]any{"scenario": "async_permanent_failure"})

	var body SendResponse
	decodeBody(t, response, &body)

	record := waitForStatus(t, server, body.ProviderMessageID, statusPermanentFailed)
	if record.Reason == nil {
		t.Fatal("expected permanent failure reason")
	}
}

func TestDeliveredStatusIsSentToWebhook(t *testing.T) {
	webhookReceived := make(chan WebhookPayload, 1)
	var webhookRequestID string

	webhookServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			t.Errorf("expected POST webhook, got %s", r.Method)
		}

		webhookRequestID = r.Header.Get("X-Request-Id")
		body, err := io.ReadAll(r.Body)
		if err != nil {
			t.Errorf("failed to read webhook body: %v", err)
			w.WriteHeader(http.StatusBadRequest)
			return
		}

		var payload WebhookPayload
		if err := json.Unmarshal(body, &payload); err != nil {
			t.Errorf("failed to decode webhook body: %v", err)
			w.WriteHeader(http.StatusBadRequest)
			return
		}

		webhookReceived <- payload
		w.WriteHeader(http.StatusAccepted)
	}))
	defer webhookServer.Close()

	server := newTestServer("mixed")
	server.config.WebhookEnabled = true

	response := postMessageWithWebhookURL(t, server, "key-1", "subscriber-1", webhookServer.URL)
	if response.Code != http.StatusAccepted {
		t.Fatalf("expected 202, got %d: %s", response.Code, response.Body.String())
	}

	var body SendResponse
	decodeBody(t, response, &body)

	select {
	case payload := <-webhookReceived:
		if payload.ProviderMessageID != body.ProviderMessageID {
			t.Fatalf("unexpected provider message id: %s", payload.ProviderMessageID)
		}
		if payload.MessageID != "notification-1" {
			t.Fatalf("unexpected message id: %s", payload.MessageID)
		}
		if payload.Status != statusDelivered {
			t.Fatalf("expected delivered webhook status, got %s", payload.Status)
		}
		if webhookRequestID != "request-1" {
			t.Fatalf("expected request id header to be propagated, got %s", webhookRequestID)
		}
	case <-time.After(time.Second):
		t.Fatal("webhook was not received")
	}
}

func newTestServer(mode string) *Server {
	return NewServer(Config{
		Address:         ":0",
		Provider:        "sms_mock",
		Channel:         "sms",
		MessageIDPrefix: "smsmsg",
		StatusMode:      mode,
		ProcessingDelay: 5 * time.Millisecond,
	}, slog.New(slog.NewTextHandler(bytes.NewBuffer(nil), nil)))
}

func postMessage(t *testing.T, server *Server, key string, recipientID string, metadata map[string]any) *httptest.ResponseRecorder {
	t.Helper()

	return postMessageWithWebhookURL(t, server, key, recipientID, "http://notification-service.test/api/providers/sms/webhooks", metadata)
}

func postMessageWithWebhookURL(t *testing.T, server *Server, key string, recipientID string, webhookURL string, metadata ...map[string]any) *httptest.ResponseRecorder {
	t.Helper()

	messageMetadata := map[string]any{"batch_id": "batch-1", "attempt": float64(1)}
	if len(metadata) > 0 && metadata[0] != nil {
		messageMetadata = metadata[0]
	}

	payload := SendRequest{
		MessageID:   "notification-1",
		RecipientID: recipientID,
		Channel:     "sms",
		Text:        "hello",
		Priority:    3,
		WebhookURL:  webhookURL,
		Metadata:    messageMetadata,
	}

	body, err := json.Marshal(payload)
	if err != nil {
		t.Fatal(err)
	}

	request := httptest.NewRequest(http.MethodPost, "/api/v1/messages", bytes.NewReader(body))
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("X-Request-Id", "request-1")
	request.Header.Set("Idempotency-Key", key)

	response := httptest.NewRecorder()
	server.Handler().ServeHTTP(response, request)
	return response
}

func waitForStatus(t *testing.T, server *Server, providerMessageID string, status string) MessageRecord {
	t.Helper()

	deadline := time.Now().Add(time.Second)
	for time.Now().Before(deadline) {
		record, ok := server.getRecord(providerMessageID)
		if ok && record.Status == status {
			return record
		}
		time.Sleep(time.Millisecond)
	}

	record, _ := server.getRecord(providerMessageID)
	t.Fatalf("status %s was not reached, current status is %s", status, record.Status)
	return MessageRecord{}
}

func decodeBody(t *testing.T, response *httptest.ResponseRecorder, value any) {
	t.Helper()

	if err := json.Unmarshal(response.Body.Bytes(), value); err != nil {
		t.Fatalf("failed to decode response: %v; body: %s", err, response.Body.String())
	}
}
