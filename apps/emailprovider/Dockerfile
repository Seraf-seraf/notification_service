FROM golang:1.26.3-alpine3.22 AS build

WORKDIR /src
COPY go.mod ./
COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /out/provider .

FROM alpine:3.22.1

RUN adduser -D -H -u 10001 app
USER app
WORKDIR /app

COPY --from=build /out/provider /app/provider

ENV HTTP_ADDR=:8082 \
    PROVIDER_NAME=email_mock \
    PROVIDER_CHANNEL=email \
    MESSAGE_ID_PREFIX=emailmsg \
    STATUS_MODE=mixed \
    PROCESSING_DELAY_MS=100 \
    WEBHOOK_ENABLED=false

EXPOSE 8082
HEALTHCHECK --interval=10s --timeout=3s --retries=3 CMD wget -qO- http://127.0.0.1:8082/health >/dev/null || exit 1

ENTRYPOINT ["/app/provider"]
