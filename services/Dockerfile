# One Dockerfile for all Go services; select the service with --build-arg SERVICE=auth|pos|finance
FROM golang:1.25-alpine AS build
WORKDIR /src
ENV CGO_ENABLED=0 GOTOOLCHAIN=local

COPY go.mod go.sum ./
RUN go mod download

ARG SERVICE
COPY pkg ./pkg
COPY ${SERVICE} ./${SERVICE}
RUN go build -trimpath -ldflags="-s -w" -o /out/app ./${SERVICE}

FROM alpine:3.20
RUN apk add --no-cache ca-certificates wget tzdata
ENV TZ=Asia/Jakarta
COPY --from=build /out/app /usr/local/bin/app
USER nobody
ENTRYPOINT ["/usr/local/bin/app"]
