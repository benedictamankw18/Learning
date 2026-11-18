FROM node:20-alpine AS envgen
WORKDIR /app
COPY . .

# API_BASE_URL may be "auto" (derive the API origin from the browser's host, so
# the same build works on localhost or any LAN IP) or an explicit URL.
ARG API_BASE_URL=auto
ARG APP_ENV=production

RUN printf "window.ENV_CONFIG={APP_ENV:'%s',API_BASE_URL:'%s'};\n" "$APP_ENV" "$API_BASE_URL" > assets/js/env.js

FROM nginx:alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=envgen /app /usr/share/nginx/html
EXPOSE 80
