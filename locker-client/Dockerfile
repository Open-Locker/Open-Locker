FROM node:22 AS builder

WORKDIR /app

COPY package.json pnpm-lock.yaml ./
RUN corepack enable pnpm && pnpm install --frozen-lockfile

COPY . .
RUN pnpm build

FROM node:22-slim

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends util-linux \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /data /config

COPY package.json ./
COPY --from=builder /app/node_modules ./node_modules
COPY --from=builder /app/dist ./dist
COPY --chmod=755 docker-entrypoint.sh /usr/local/bin/locker-client-entrypoint

VOLUME ["/data", "/config"]

ENTRYPOINT ["locker-client-entrypoint"]
CMD ["node", "dist/src/main.js"]
