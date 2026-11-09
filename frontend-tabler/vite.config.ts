import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [vue()],
  server: {
    port: 5174,
    host: '0.0.0.0',
    strictPort: true,
    watch: {
      usePolling: true,
      interval: 100
    },
    hmr: {
      overlay: true
    },
    proxy: {
      '/sanctum': {
        target: 'http://127.0.0.1:8001',
        changeOrigin: true,
        secure: false,
        cookieDomainRewrite: '',
      },
      '/api': {
        target: 'http://127.0.0.1:8001',
        changeOrigin: true,
        secure: false,
        cookieDomainRewrite: '',
      }
    }
  }
})
