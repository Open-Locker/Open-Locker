# Stage 1: Get Packages
FROM node:22 AS builder

WORKDIR /app

COPY package.json pnpm-lock.yaml ./
RUN corepack enable pnpm && pnpm install --frozen-lockfile

# Stage 2: Build Application
COPY . .
RUN pnpm build

# Stage 3: Production
FROM node:22-slim

WORKDIR /app

COPY package.json ./
COPY --from=builder /app/node_modules ./node_modules
COPY --from=builder /app/dist ./dist

# Create directories for volumes
RUN mkdir -p /data /config

# Declare volumes for persistent data and configuration
VOLUME ["/data", "/config"]

CMD ["node", "dist/app.js"]