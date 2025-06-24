# Stage 1: Build
FROM node:22 AS builder

WORKDIR /app

COPY package*.json ./

RUN yarn install --frozen-lockfile --production

COPY . .
RUN yarn build

# Stage 2: Production
FROM node:22-slim

WORKDIR /app

COPY --from=builder /app/yarn.lock ./
COPY --from=builder /app/package.json ./
COPY --from=builder /app/dist ./dist

CMD ["node", "dist/app.js"]