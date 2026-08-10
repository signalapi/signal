FROM mcr.microsoft.com/playwright:v1.49.0-jammy

WORKDIR /app
RUN npm init -y >/dev/null 2>&1 && npm install playwright@1.49.0 --no-audit --no-fund
COPY server.js .

EXPOSE 7300
CMD ["node", "server.js"]
