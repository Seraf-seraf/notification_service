package main

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

const (
	statusAccepted         = "accepted"
	statusProcessing       = "processing"
	statusDelivered        = "delivered"
	statusTemporaryFailed  = "temporary_failed"
	statusPermanentFailed  = "permanent_failed"
	statusInvalidRecipient = "invalid_recipient"
	statusExpired          = "expired"
)

type Config struct {
	Address           string
	Provider          string
	Channel           string
	MessageIDPrefix   string
	StatusMode        string
	ProcessingDelay   time.Duration
	WebhookEnabled    bool
	DefaultWebhookURL string
}

type Server struct {
	config      Config
	httpClient  *http.Client
	logger      *slog.Logger
	counter     atomic.Uint64
	mu          sync.RWMutex
	messages    map[string]*MessageRecord
	idempotency map[string]string
	payloadHash map[string]string
}

type SendRequest struct {
	MessageID   string         `json:"message_id"`
	RecipientID string         `json:"recipient_id"`
	Channel     string         `json:"channel"`
	Text        string         `json:"text"`
	Priority    int            `json:"priority"`
	WebhookURL  string         `json:"webhook_url"`
	Metadata    map[string]any `json:"metadata"`
}

type SendResponse struct {
	ProviderMessageID string `json:"provider_message_id"`
	Status            string `json:"status"`
	Deduplicated      bool   `json:"deduplicated"`
}

type ErrorResponse struct {
	Error             string `json:"error"`
	Message           string `json:"message,omitempty"`
	RetryAfterSeconds int    `json:"retry_after_seconds,omitempty"`
}

type MessageRecord struct {
	ProviderMessageID string         `json:"provider_message_id"`
	MessageID         string         `json:"message_id"`
	Status            string         `json:"status"`
	Reason            *string        `json:"reason"`
	UpdatedAt         time.Time      `json:"updated_at"`
	RequestID         string         `json:"-"`
	WebhookURL        string         `json:"-"`
	Metadata          map[string]any `json:"-"`
}

type WebhookPayload struct {
	ProviderMessageID string         `json:"provider_message_id"`
	MessageID         string         `json:"message_id"`
	Status            string         `json:"status"`
	Reason            *string        `json:"reason"`
	OccurredAt        time.Time      `json:"occurred_at"`
	Metadata          map[string]any `json:"metadata"`
}

func main() {
	config := loadConfig()
	logger := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	server := NewServer(config, logger)

	httpServer := &http.Server{
		Addr:              config.Address,
		Handler:           server.Handler(),
		ReadHeaderTimeout: 5 * time.Second,
	}

	logger.Info("starting mock provider", "provider", config.Provider, "channel", config.Channel, "address", config.Address)

	if err := httpServer.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		logger.Error("mock provider stopped", "error", err)
		os.Exit(1)
	}
}

func NewServer(config Config, logger *slog.Logger) *Server {
	if logger == nil {
		logger = slog.New(slog.NewJSONHandler(os.Stdout, nil))
	}

	return &Server{
		config: config,
		httpClient: &http.Client{
			Timeout: 3 * time.Second,
		},
		logger:      logger,
		messages:    make(map[string]*MessageRecord),
		idempotency: make(map[string]string),
		payloadHash: make(map[string]string),
	}
}

func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", s.handleHealth)
	mux.HandleFunc("POST /api/v1/messages", s.handleSend)
	mux.HandleFunc("GET /api/v1/messages/{provider_message_id}", s.handleStatus)
	return requestLogMiddleware(s.logger, mux)
}

func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]string{
		"status":   "ok",
		"provider": s.config.Provider,
		"channel":  s.config.Channel,
	})
}

func (s *Server) handleSend(w http.ResponseWriter, r *http.Request) {
	var request SendRequest
	decoder := json.NewDecoder(http.MaxBytesReader(w, r.Body, 1<<20))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&request); err != nil {
		writeJSON(w, http.StatusBadRequest, ErrorResponse{Error: "invalid_json", Message: err.Error()})
		return
	}

	if err := s.validateSendRequest(request); err != nil {
		writeJSON(w, http.StatusBadRequest, ErrorResponse{Error: "validation_error", Message: err.Error()})
		return
	}

	idempotencyKey := strings.TrimSpace(r.Header.Get("Idempotency-Key"))
	if idempotencyKey == "" {
		writeJSON(w, http.StatusBadRequest, ErrorResponse{Error: "missing_idempotency_key", Message: "Idempotency-Key header is required"})
		return
	}

	requestID := strings.TrimSpace(r.Header.Get("X-Request-Id"))
	payloadHash := hashSendRequest(request)

	s.mu.RLock()
	existingID, exists := s.idempotency[idempotencyKey]
	existingHash := s.payloadHash[idempotencyKey]
	s.mu.RUnlock()

	if exists {
		if existingHash != payloadHash {
			writeJSON(w, http.StatusConflict, ErrorResponse{Error: "idempotency_conflict", Message: "Idempotency-Key was already used with another payload"})
			return
		}

		record, ok := s.getRecord(existingID)
		if !ok {
			writeJSON(w, http.StatusInternalServerError, ErrorResponse{Error: "idempotency_state_lost"})
			return
		}

		writeJSON(w, http.StatusOK, SendResponse{ProviderMessageID: record.ProviderMessageID, Status: statusAccepted, Deduplicated: true})
		return
	}

	scenario := s.resolveScenario(request)
	switch scenario {
	case "temporary_failure":
		writeJSON(w, http.StatusServiceUnavailable, ErrorResponse{Error: "provider_unavailable", RetryAfterSeconds: 30})
		return
	case "permanent_failure", "invalid_recipient":
		writeJSON(w, http.StatusUnprocessableEntity, ErrorResponse{Error: "invalid_recipient", Message: "Recipient phone does not exist"})
		return
	}

	providerMessageID := s.nextProviderMessageID()
	record := &MessageRecord{
		ProviderMessageID: providerMessageID,
		MessageID:         request.MessageID,
		Status:            statusAccepted,
		UpdatedAt:         time.Now().UTC(),
		RequestID:         requestID,
		WebhookURL:        s.resolveWebhookURL(request.WebhookURL),
		Metadata:          request.Metadata,
	}

	s.mu.Lock()
	s.messages[providerMessageID] = record
	s.idempotency[idempotencyKey] = providerMessageID
	s.payloadHash[idempotencyKey] = payloadHash
	s.mu.Unlock()

	go s.processAsync(providerMessageID, scenario)

	writeJSON(w, http.StatusAccepted, SendResponse{ProviderMessageID: providerMessageID, Status: statusAccepted, Deduplicated: false})
}

func (s *Server) handleStatus(w http.ResponseWriter, r *http.Request) {
	providerMessageID := strings.TrimSpace(r.PathValue("provider_message_id"))
	record, ok := s.getRecord(providerMessageID)
	if !ok {
		writeJSON(w, http.StatusNotFound, ErrorResponse{Error: "message_not_found"})
		return
	}

	writeJSON(w, http.StatusOK, record)
}

func (s *Server) validateSendRequest(request SendRequest) error {
	if strings.TrimSpace(request.MessageID) == "" {
		return errors.New("message_id is required")
	}
	if strings.TrimSpace(request.RecipientID) == "" {
		return errors.New("recipient_id is required")
	}
	if request.Channel != s.config.Channel {
		return fmt.Errorf("channel must be %s", s.config.Channel)
	}
	if strings.TrimSpace(request.Text) == "" {
		return errors.New("text is required")
	}
	if request.Priority < 1 || request.Priority > 3 {
		return errors.New("priority must be between 1 and 3")
	}
	return nil
}

func (s *Server) processAsync(providerMessageID string, scenario string) {
	s.setStatus(providerMessageID, statusProcessing, nil)

	delay := s.config.ProcessingDelay
	if delay <= 0 {
		delay = 50 * time.Millisecond
	}
	time.Sleep(delay)

	finalStatus := statusDelivered
	var reason *string
	switch scenario {
	case "async_permanent_failure":
		finalStatus = statusPermanentFailed
		reason = ptr("Provider rejected message during async delivery")
	case "expired":
		finalStatus = statusExpired
		reason = ptr("Provider delivery window expired")
	}

	record, ok := s.setStatus(providerMessageID, finalStatus, reason)
	if ok && s.config.WebhookEnabled && record.WebhookURL != "" {
		s.sendWebhook(record)
	}
}

func (s *Server) sendWebhook(record MessageRecord) {
	payload := WebhookPayload{
		ProviderMessageID: record.ProviderMessageID,
		MessageID:         record.MessageID,
		Status:            record.Status,
		Reason:            record.Reason,
		OccurredAt:        record.UpdatedAt,
		Metadata: map[string]any{
			"request_id": record.RequestID,
		},
	}

	body, err := json.Marshal(payload)
	if err != nil {
		s.logger.Error("failed to marshal webhook", "provider_message_id", record.ProviderMessageID, "error", err)
		return
	}

	request, err := http.NewRequestWithContext(context.Background(), http.MethodPost, record.WebhookURL, bytes.NewReader(body))
	if err != nil {
		s.logger.Error("failed to build webhook request", "provider_message_id", record.ProviderMessageID, "error", err)
		return
	}
	request.Header.Set("Content-Type", "application/json")
	if record.RequestID != "" {
		request.Header.Set("X-Request-Id", record.RequestID)
	}

	response, err := s.httpClient.Do(request)
	if err != nil {
		s.logger.Error("failed to send webhook", "provider_message_id", record.ProviderMessageID, "error", err)
		return
	}
	defer response.Body.Close()

	if response.StatusCode >= http.StatusBadRequest {
		s.logger.Warn("webhook returned non-success response", "provider_message_id", record.ProviderMessageID, "status_code", response.StatusCode)
	}
}

func (s *Server) resolveScenario(request SendRequest) string {
	if s.config.StatusMode != "" && s.config.StatusMode != "mixed" {
		return s.config.StatusMode
	}

	if value, ok := request.Metadata["scenario"].(string); ok && value != "" {
		return normalizeScenario(value)
	}

	text := strings.ToLower(request.RecipientID + " " + request.Text)
	switch {
	case strings.Contains(text, "temporary-failure"), strings.Contains(text, "temporary_failure"), strings.Contains(text, "temp-fail"):
		return "temporary_failure"
	case strings.Contains(text, "async-permanent"), strings.Contains(text, "async_permanent"):
		return "async_permanent_failure"
	case strings.Contains(text, "permanent-failure"), strings.Contains(text, "permanent_failure"), strings.Contains(text, "invalid"):
		return "invalid_recipient"
	case strings.Contains(text, "expired"):
		return "expired"
	default:
		return "success"
	}
}

func (s *Server) resolveWebhookURL(requestURL string) string {
	if s.config.DefaultWebhookURL != "" {
		return s.config.DefaultWebhookURL
	}
	return strings.TrimSpace(requestURL)
}

func (s *Server) nextProviderMessageID() string {
	next := s.counter.Add(1)
	return fmt.Sprintf("%s-%06d", s.config.MessageIDPrefix, next)
}

func (s *Server) getRecord(providerMessageID string) (MessageRecord, bool) {
	s.mu.RLock()
	defer s.mu.RUnlock()

	record, ok := s.messages[providerMessageID]
	if !ok {
		return MessageRecord{}, false
	}
	return *record, true
}

func (s *Server) setStatus(providerMessageID string, status string, reason *string) (MessageRecord, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()

	record, ok := s.messages[providerMessageID]
	if !ok {
		return MessageRecord{}, false
	}

	record.Status = status
	record.Reason = reason
	record.UpdatedAt = time.Now().UTC()
	return *record, true
}

func loadConfig() Config {
	return Config{
		Address:           env("HTTP_ADDR", ":8081"),
		Provider:          env("PROVIDER_NAME", "sms_mock"),
		Channel:           env("PROVIDER_CHANNEL", "sms"),
		MessageIDPrefix:   env("MESSAGE_ID_PREFIX", "smsmsg"),
		StatusMode:        normalizeScenario(env("STATUS_MODE", "mixed")),
		ProcessingDelay:   envDuration("PROCESSING_DELAY_MS", 100*time.Millisecond),
		WebhookEnabled:    envBool("WEBHOOK_ENABLED", true),
		DefaultWebhookURL: strings.TrimSpace(os.Getenv("WEBHOOK_URL")),
	}
}

func hashSendRequest(request SendRequest) string {
	body, _ := json.Marshal(request)
	sum := sha256.Sum256(body)
	return hex.EncodeToString(sum[:])
}

func normalizeScenario(value string) string {
	return strings.ReplaceAll(strings.ToLower(strings.TrimSpace(value)), "-", "_")
}

func requestLogMiddleware(logger *slog.Logger, next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		startedAt := time.Now()
		recorder := &statusRecorder{ResponseWriter: w, statusCode: http.StatusOK}
		next.ServeHTTP(recorder, r)
		logger.Info("http request",
			"method", r.Method,
			"path", r.URL.Path,
			"status_code", recorder.statusCode,
			"duration_ms", time.Since(startedAt).Milliseconds(),
			"request_id", r.Header.Get("X-Request-Id"),
		)
	})
}

type statusRecorder struct {
	http.ResponseWriter
	statusCode int
}

func (r *statusRecorder) WriteHeader(statusCode int) {
	r.statusCode = statusCode
	r.ResponseWriter.WriteHeader(statusCode)
}

func writeJSON(w http.ResponseWriter, statusCode int, value any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(statusCode)
	_ = json.NewEncoder(w).Encode(value)
}

func env(name string, fallback string) string {
	value := strings.TrimSpace(os.Getenv(name))
	if value == "" {
		return fallback
	}
	return value
}

func envBool(name string, fallback bool) bool {
	value := strings.TrimSpace(os.Getenv(name))
	if value == "" {
		return fallback
	}
	parsed, err := strconv.ParseBool(value)
	if err != nil {
		return fallback
	}
	return parsed
}

func envDuration(name string, fallback time.Duration) time.Duration {
	value := strings.TrimSpace(os.Getenv(name))
	if value == "" {
		return fallback
	}
	ms, err := strconv.Atoi(value)
	if err != nil || ms < 0 {
		return fallback
	}
	return time.Duration(ms) * time.Millisecond
}

func ptr(value string) *string {
	return &value
}
