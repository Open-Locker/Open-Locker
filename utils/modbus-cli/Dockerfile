FROM node:20-alpine

# Install build dependencies
RUN apk add --no-cache \
    python3 \
    make \
    g++ \
    linux-headers

WORKDIR /app

# Copy package files
COPY package*.json ./
COPY tsconfig.json ./

# Install dependencies
RUN npm install

# Copy source code
COPY src ./src

# Build TypeScript
RUN npm run build

# Create a symbolic link for easy execution
RUN ln -s /app/dist/index.js /usr/local/bin/modbus-cli && \
    chmod +x /app/dist/index.js

# Default command shows help
ENTRYPOINT ["node", "/app/dist/index.js"]
CMD ["--help"]
