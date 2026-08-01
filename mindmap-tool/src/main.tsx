import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'

import './styles/global.css'
import './styles/admin.css'
import App from './App.tsx'
import { AuthProvider } from './auth/AuthProvider'
import { AdminConsolePage } from './pages/AdminConsolePage'
import { DashboardPage } from './pages/DashboardPage'
import { LoginPage } from './pages/LoginPage'
import { RequireAdmin } from './routes/RequireAdmin'
import { RequireAuth } from './routes/RequireAuth'

// Phase 2 で画面が複数になったためルーティングを導入した
// （ADR: mindmap-tool/docs/adr/20260801_フロントエンドルーティングライブラリ選定.md）。
// `/editor` は Phase 1 からのマインドマップ編集画面。
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route
            path="/dashboard"
            element={
              <RequireAuth>
                <DashboardPage />
              </RequireAuth>
            }
          />
          <Route
            path="/admin"
            element={
              <RequireAdmin>
                <AdminConsolePage />
              </RequireAdmin>
            }
          />
          <Route
            path="/editor"
            element={
              <RequireAuth>
                <App />
              </RequireAuth>
            }
          />
          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  </StrictMode>,
)
