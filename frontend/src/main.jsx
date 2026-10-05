import React from 'react'
import ReactDOM from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'

import AppRoutes from './App'
import { AuthProvider } from './context/AuthContext'
import { SemesterProvider } from './context/SemesterContext'
import './index.css'

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <BrowserRouter>
      <AuthProvider>
        {/* Inside AuthProvider because the term list is scoped to the signed-in
            user — `/semesters/current` resolves to their own enrolment where
            they have one. */}
        <SemesterProvider>
          <AppRoutes />
        </SemesterProvider>
      </AuthProvider>
    </BrowserRouter>
  </React.StrictMode>,
)