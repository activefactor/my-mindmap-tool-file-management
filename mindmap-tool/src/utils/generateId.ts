export const generateId = (): string =>
  `node-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;
