# Lighthouse CLI for `make lighthouse` (tooling only, never runtime; research D9, E7: needs node >= 22.19).
FROM node:22-bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends chromium && rm -rf /var/lib/apt/lists/*
ARG LIGHTHOUSE_VERSION=13.5.0
RUN npm install -g lighthouse@${LIGHTHOUSE_VERSION} && npm cache clean --force
ENV CHROME_PATH=/usr/bin/chromium
COPY run.sh /usr/local/bin/lighthouse-run
COPY print-scores.mjs /usr/local/lib/print-scores.mjs
RUN chmod +x /usr/local/bin/lighthouse-run
ENTRYPOINT ["lighthouse-run"]
