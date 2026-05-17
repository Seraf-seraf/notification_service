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

ENV HTTP_ADDR=:8081 \
    PROVIDER_NAME=sms_mock \
    PROVIDER_CHANNEL=sms \
    MESSAGE_ID_PREFIX=smsmsg \
    STATUS_MODE=mixed \
    PROCESSING_DELAY_MS=100 \
    WEBHOOK_ENABLED=true

EXPOSE 8081
HEALTHCHECK --interval=10s --timeout=3s --retries=3 CMD wget -qO- http://127.0.0.1:8081/health >/dev/null || exit 1

ENTRYPOINT ["/app/provider"]
