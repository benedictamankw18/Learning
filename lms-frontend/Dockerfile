FROM node:20-alpine AS envgen
WORKDIR /app
COPY . .
ARG API_BASE_URL=http://localhost:8081
ARG APP_ENV=production
RUN node scripts/generate-env.js 2>/dev/null || (echo "window.ENV_CONFIG={APP_ENV:'${APP_ENV}',API_BASE_URL:'${API_BASE_URL}'};" > assets/js/env.js)

FROM nginx:alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=envgen /app /usr/share/nginx/html
EXPOSE 80
