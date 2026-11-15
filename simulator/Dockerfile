# The runner of the Traffic lab. It has no dependencies at run time: Node runs the TypeScript sources as they are.
# Build from the simulator directory:  docker build -t yadeit-simulator simulator
FROM node:24-alpine
WORKDIR /sim
COPY package.json ./
COPY src ./src
USER node
ENTRYPOINT ["node", "src/main.ts"]
