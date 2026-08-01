import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  // Phase 2 は独自ドメイン（mindmap.activefactor.org）のルートに配置するため base は '/'。
  // Phase 1 の GitHub Pages 用サブパス指定は不要になった。
  base: '/',
  server: {
    proxy: {
      // 開発時は Vite(5173) から Docker のPHP(8080)へAPIを転送する。
      // 同一オリジンにすることで、セッションCookieとCSRFのOrigin検証が本番と同じ条件になる。
      '/api': {
        target: 'http://localhost:8080',
        changeOrigin: false,
      },
    },
  },
})
