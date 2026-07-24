# ── Stage 1: Build ───────────────────────────────────────────────────────────
FROM node:20-alpine AS builder
ARG VITE_API_BASE_URL=/api/v1
ENV VITE_API_BASE_URL=$VITE_API_BASE_URL
WORKDIR /app
COPY package*.json ./
# npm ci uses the lockfile exactly — reproducible builds, no version drift
RUN npm ci --prefer-offline
COPY . .
RUN npm run build

# ── Stage 2: Serve ───────────────────────────────────────────────────────────
FROM nginx:stable-alpine
COPY --from=builder /app/dist /usr/share/nginx/html
# Template processed at startup by the official nginx image entrypoint
COPY nginx.conf /etc/nginx/templates/default.conf.template
ENV FRONTEND_SERVER_NAME=clinicaldashboard.mloganzila.or.tz
ENV BACKEND_UPSTREAM=http://dashboard_backend:80
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -sf http://localhost/ > /dev/null || exit 1

CMD ["nginx", "-g", "daemon off;"]
