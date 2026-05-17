package main

import (
	"bytes"
	"encoding/json"
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

func newTestServer(mode string) *Server {
	return NewServer(Config{
		Address:         ":0",
		Provider:        "email_mock",
		Channel:         "email",
		MessageIDPrefix: "emailmsg",
		StatusMode:      mode,
		ProcessingDelay: 5 * time.Millisecond,
	}, slog.New(slog.NewTextHandler(bytes.NewBuffer(nil), nil)))
}

func postMessage(t *testing.T, server *Server, key string, recipientID string, metadata map[string]any) *httptest.ResponseRecorder {
	t.Helper()

	if metadata == nil {
		metadata = map[string]any{"batch_id": "batch-1", "attempt": float64(1)}
	}

	payload := SendRequest{
		MessageID:   "notification-1",
		RecipientID: recipientID,
		Channel:     "email",
		Text:        "hello",
		Priority:    3,
		WebhookURL:  "http://notification-service.test/api/providers/email/webhooks",
		Metadata:    metadata,
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
