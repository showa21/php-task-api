FROM php:8.3-cli

WORKDIR /app
COPY . /app

# SQLite file lives in data/. The container's disk is temporary on free hosts,
# so data resets on each restart or redeploy - fine for a demo.
RUN mkdir -p data && chmod 777 data

# Hosts such as Render tell the app which port to use via $PORT.
ENV PORT=8000
EXPOSE 8000
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t public public/index.php"]
