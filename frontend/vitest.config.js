import { defineConfig } from 'vitest/config'

// Config separada de vite.config (evita el plugin PWA en los tests)
export default defineConfig({
  test: {
    environment: 'node',
    include: ['src/**/*.test.{js,jsx}'],
  },
})
