import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App.tsx'
import './styles/globals.css'

const rootElement = document.getElementById('sbwp-admin-root');
if (rootElement) {
    ReactDOM.createRoot(rootElement).render(
        <React.StrictMode>
            <App />
        </React.StrictMode>,
    )
} else {
    // Fallback for dev mode outside WP or if root missing
    const devRoot = document.getElementById('root');
    if (devRoot) {
        ReactDOM.createRoot(devRoot).render(
            <React.StrictMode>
                <App />
            </React.StrictMode>,
        )
    }
}
