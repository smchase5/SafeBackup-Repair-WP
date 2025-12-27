import React from 'react'
import ReactDOM from 'react-dom/client'
import { RecoveryApp } from './features/recovery/RecoveryApp'
import './styles/globals.css'

// Force dark mode for recovery portal
document.documentElement.classList.add('dark')

const rootElement = document.getElementById('sbwp-recovery-root');
if (rootElement) {
    ReactDOM.createRoot(rootElement).render(
        <React.StrictMode>
            <RecoveryApp />
        </React.StrictMode>,
    )
}
