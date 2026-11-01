FROM node:20-alpine AS envgen
WORKDIR /app
COPY . .

# The public API URL must be supplied explicitly at build time. There is no
# localhost fallback: it would break for anyone accessing the site remotely.
ARG API_BASE_URL
ARG APP_ENV=production

RUN test -n "$API_BASE_URL" || (echo "ERROR: API_BASE_URL build arg is required (for example https://api.example.com)." >&2; exit 1)
RUN printf "window.ENV_CONFIG={APP_ENV:'%s',API_BASE_URL:'%s'};\n" "$APP_ENV" "$API_BASE_URL" > assets/js/env.js

FROM nginx:alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=envgen /app /usr/share/nginx/html
EXPOSE 80
