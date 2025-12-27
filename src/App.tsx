import { useState } from "react"
import { DashboardPage } from "./features/dashboard/DashboardPage"
import { SettingsPage } from "./features/settings/SettingsPage"
import { SchedulesManager } from "./features/schedules/SchedulesManager"

function App() {
    const [view, setView] = useState<'dashboard' | 'settings' | 'schedules'>('dashboard')

    return (
        <div className="min-h-screen bg-background p-8 font-sans antialiased text-foreground">
            <div className="mx-auto max-w-6xl space-y-8">
                <div className="flex justify-between items-center">
                    <h1 className="text-3xl font-bold tracking-tight">SafeBackup & Repair</h1>
                    <div className="flex items-center gap-2">
                        {window.sbwpData.isPro ? (
                            <span className="text-xs font-medium text-emerald-600 bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-400 px-2 py-1 rounded-full border border-emerald-200 dark:border-emerald-800">Pro Active</span>
                        ) : (
                            <span className="text-xs font-medium text-slate-600 bg-slate-100 dark:bg-slate-900/30 dark:text-slate-400 px-2 py-1 rounded-full border border-slate-200 dark:border-slate-800">Free Edition</span>
                        )}
                    </div>
                </div>

                {view === 'dashboard' && <DashboardPage onNavigate={setView} />}
                {view === 'settings' && <SettingsPage onBack={() => setView('dashboard')} />}
                {view === 'schedules' && <SchedulesManager onBack={() => setView('dashboard')} />}
            </div>
        </div>
    )
}

export default App
