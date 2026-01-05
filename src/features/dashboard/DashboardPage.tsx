import { useEffect, useState } from "react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Switch } from "@/components/ui/switch"
import { Label } from "@/components/ui/label"
import { ShieldCheck, HardDrive, RefreshCw, Loader2, ArrowUpCircle, Database, Archive, Search, AlertTriangle, ExternalLink, Zap, ChevronDown, ChevronUp, XCircle } from "lucide-react"
import { Progress } from "@/components/ui/progress"
import { fetchStats, createBackup, fetchProgress, getCrashStatus, getSettings, saveSettings, cancelBackup, type Stats, type CrashStatus } from "@/lib/api"
import { useToast } from "@/components/ui/use-toast"

import { SafeUpdateDialog } from "./SafeUpdateDialog"
import { ConflictScannerDialog } from "./ConflictScannerDialog"
import { BackupsList } from "./BackupsList"

interface DashboardPageProps {
    onNavigate?: (view: 'dashboard' | 'settings' | 'schedules') => void
}

export function DashboardPage({ onNavigate: _onNavigate }: DashboardPageProps) {
    const [stats, setStats] = useState<Stats | null>(null)
    const [loading, setLoading] = useState(true)
    const [backingUp, setBackingUp] = useState(false)
    const [showSafeUpdate, setShowSafeUpdate] = useState(false)
    const [showScanner, setShowScanner] = useState(false)
    const [refreshBackups, setRefreshBackups] = useState(0)
    const [progress, setProgress] = useState(0)
    const [progressMsg, setProgressMsg] = useState('')
    const [crashStatus, setCrashStatus] = useState<CrashStatus | null>(null)
    const [incrementalEnabled, setIncrementalEnabled] = useState(true)
    const [showAdvanced, setShowAdvanced] = useState(false)
    const [forceFullBackup, setForceFullBackup] = useState(false)
    const [dbOnlyBackup, setDbOnlyBackup] = useState(false)
    const [sessionId, setSessionId] = useState<string | null>(null)
    const { toast } = useToast()

    const loadStats = async () => {
        try {
            const data = await fetchStats()
            setStats(data)
        } catch (error) {
            console.error("Failed to load stats", error)
        } finally {
            setLoading(false)
        }
    }

    useEffect(() => {
        loadStats()
        getCrashStatus().then(setCrashStatus).catch(console.error)
        // Load incremental setting
        getSettings().then(s => setIncrementalEnabled(s.incremental_enabled ?? true)).catch(console.error)
    }, [])

    // Old effect removed


    const handleBackup = async () => {
        // Determine backup type based on settings
        let backupType: 'full' | 'incremental' | 'db_only' = 'full'
        if (dbOnlyBackup) {
            backupType = 'db_only'
        } else if (incrementalEnabled && !forceFullBackup) {
            backupType = 'incremental'
        }

        const sid = Date.now().toString()
        setSessionId(sid)
        setBackingUp(true)
        setProgress(1)
        setProgressMsg('Initializing backup process...')

        try {
            // Kick off the background process logic
            const result = await createBackup(backupType, false, sid)
            if (result.code) throw new Error(result.message);
            // We do NOT wait here. The useEffect interval will pick up the progress.
            toast({
                title: "Backup Started",
                description: "Your backup is running in the background. You can leave this page.",
            })
        } catch (error: any) {
            console.error(error)
            setBackingUp(false) // Only reset if start failed
            setSessionId(null)
            toast({
                title: "Backup Failed to Start",
                description: error.message || "Could not initiate backup.",
                variant: "destructive"
            })
        } finally {
            setForceFullBackup(false)
            setDbOnlyBackup(false)
        }
    }

    // Effect to handle progress polling and completion
    useEffect(() => {
        let interval: NodeJS.Timeout | undefined;

        const checkProgress = async () => {
            try {
                const p = await fetchProgress()

                // ADOPTION LOGIC:
                // If we have no local session (refresh/navigated away), and server has ACTIVE backup: ADOPT IT.
                // If we have local session, enforce match.

                if (sessionId) {
                    // We have a session - enforce strict matching
                    if (p.session_id && p.session_id !== sessionId) {
                        return;
                    }
                } else {
                    // No local session. Check if there's an active backup to adopt
                    if (p.active && p.percent > 0 && p.percent < 100) {
                        setSessionId(p.session_id || 'legacy');
                        setBackingUp(true);
                        setProgress(p.percent);
                        if (p.message) setProgressMsg(p.message);
                        return;
                    } else {
                        return;
                    }
                }

                // From here, we have a matching session - update progress

                // Check for COMPLETION first (active=false means it finished)
                if (!p.active && p.percent >= 100) {
                    setBackingUp(false);
                    setSessionId(null);
                    setProgress(100);
                    setProgressMsg('Backup Complete');
                    await loadStats();
                    setRefreshBackups(prev => prev + 1);
                    if (interval) clearInterval(interval);
                    toast({ title: "Backup Complete", description: "Background backup finished successfully." });
                    return;
                }

                // Still running - update progress
                if (p.active) {
                    if (p.percent > progress) {
                        setProgress(p.percent);
                    }
                    if (p.message) setProgressMsg(p.message);

                    // Also check for completion within active (backup might report 100% while still "active")
                    if (p.percent >= 100 || (p.message && p.message.includes('Complete'))) {
                        setBackingUp(false);
                        setSessionId(null);
                        setProgress(100);
                        setProgressMsg('Backup Complete');
                        await loadStats();
                        setRefreshBackups(prev => prev + 1);
                        if (interval) clearInterval(interval);
                        toast({ title: "Backup Complete", description: "Background backup finished successfully." });
                    }
                }

            } catch (e) {
                console.error("Backup progress poll error", e);
            }
        };

        // ALWAYS poll - we need to detect orphaned active backups after navigation
        checkProgress(); // Initial check on mount/change
        interval = setInterval(checkProgress, 2000); // Poll every 2 seconds

        return () => {
            if (interval) clearInterval(interval);
        };
    }, [backingUp, sessionId, progress])

    // Sync local backingUp state with polling effect (removed the dependent effect below)


    const handleIncrementalToggle = async (enabled: boolean) => {
        console.log('SBWP: Toggling incremental to:', enabled)
        setIncrementalEnabled(enabled)
        try {
            const result = await saveSettings({ retention_limit: 5, incremental_enabled: enabled })
            console.log('SBWP: Settings saved, result:', result)
            // Refresh backups list to update lock icons
            setRefreshBackups(prev => prev + 1)
        } catch (e: any) {
            console.error('SBWP: Failed to save incremental setting:', e?.message || e)
            toast({ title: "Error", description: e?.message || "Failed to save setting", variant: "destructive" })
            // Revert toggle on error
            setIncrementalEnabled(!enabled)
        }
    }

    const handleCancel = async () => {
        try {
            await cancelBackup();
            setBackingUp(false);
            setProgress(0);
            setProgressMsg('IDLE');
            setRefreshBackups(prev => prev + 1);
            toast({ title: "Backup Cancelled", description: "The backup process was stopped." });
        } catch (e: any) {
            console.error("Cancel failed", e);
            toast({ title: "Error", description: "Failed to stop backup", variant: "destructive" });
        }
    }

    if (loading) {
        return <div className="p-8 flex justify-center"><Loader2 className="h-8 w-8 animate-spin text-muted-foreground" /></div>
    }

    return (
        <div className="space-y-6">

            <SafeUpdateDialog open={showSafeUpdate} onOpenChange={setShowSafeUpdate} />
            <ConflictScannerDialog open={showScanner} onOpenChange={setShowScanner} />

            {/* Crash Detection Banner */}
            {crashStatus?.has_crash && (
                <div className="bg-red-500/10 border border-red-500/30 rounded-lg p-4 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="bg-red-500/20 p-2 rounded-full">
                            <AlertTriangle className="h-5 w-5 text-red-500" />
                        </div>
                        <div>
                            <h3 className="font-medium text-red-800 dark:text-red-200">Site Issues Detected</h3>
                            <p className="text-sm text-red-600 dark:text-red-400 truncate max-w-lg">
                                Recent errors found in debug.log
                            </p>
                        </div>
                    </div>
                    <Button
                        variant="destructive"
                        size="sm"
                        onClick={() => window.open(crashStatus.recovery_url, '_blank')}
                    >
                        <ExternalLink className="h-4 w-4 mr-2" />
                        Open Recovery Portal
                    </Button>
                </div>
            )}

            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">
                            Last Backup
                        </CardTitle>
                        <ShieldCheck className="h-4 w-4 text-emerald-500" />
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">{stats?.last_backup || 'Never'}</div>
                        <p className="text-xs text-muted-foreground">
                            {stats?.count || 0} total backups
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">
                            Storage Usage
                        </CardTitle>
                        <HardDrive className="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">{stats?.total_size || '0 B'}</div>
                        <p className="text-xs text-muted-foreground">
                            Local storage used
                        </p>
                    </CardContent>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">
                            {backingUp ? 'Backing up...' : 'Backup'}
                        </CardTitle>
                        <RefreshCw className={`h-4 w-4 text-muted-foreground ${backingUp ? 'animate-spin' : ''}`} />
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {backingUp ? (
                            <div className="relative overflow-hidden rounded-md bg-zinc-950 p-4 border border-zinc-800 shadow-inner">
                                {/* "Terminal" Glint Effect */}
                                <div className="absolute top-0 left-0 w-full h-[1px] bg-gradient-to-r from-transparent via-zinc-500 to-transparent opacity-50"></div>

                                <div className="space-y-4 relative z-10">
                                    <div className="flex justify-between items-center pb-2 border-b border-zinc-800">
                                        <div className="flex items-center gap-2">
                                            <div className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></div>
                                            <span className="text-xs font-mono text-zinc-300 uppercase tracking-wider">Processing Backup</span>
                                            <button
                                                onClick={handleCancel}
                                                className="ml-2 text-zinc-600 hover:text-red-400 transition-colors"
                                                title="Stop Backup"
                                            >
                                                <XCircle className="w-4 h-4" />
                                            </button>
                                        </div>
                                        <span className="text-xs font-mono text-emerald-500 font-bold">{progress}%</span>
                                    </div>



                                    <div className="space-y-1 py-1">
                                        <div className="font-mono text-[10px] text-zinc-400 flex gap-2">
                                            <span className="text-emerald-500">➜</span>
                                            <span>Task: {progressMsg.split('(')[0]}</span>
                                        </div>
                                        <div className="font-mono text-[10px] text-zinc-300 flex gap-2 truncate">
                                            <span className="text-blue-400">ℹ</span>
                                            <span className="opacity-90 break-all">{progressMsg}</span>
                                        </div>
                                    </div>

                                    <div className="space-y-1">
                                        <Progress value={progress} className="h-1 bg-zinc-800" indicatorClassName="bg-gradient-to-r from-emerald-600 to-emerald-400" />
                                        <div className="flex justify-between text-[9px] text-zinc-600 uppercase font-mono">
                                            <span>Start</span>
                                            <span>End</span>
                                        </div>
                                    </div>
                                    <div className="text-[10px] text-zinc-700 font-mono mt-2 bg-zinc-900/50 p-1 rounded border border-zinc-800 break-all">
                                        RAW: {JSON.stringify({ p: progress, m: progressMsg })}
                                    </div>

                                </div>
                                <div className="absolute -bottom-4 -right-4 text-zinc-800 opacity-20">
                                    <ShieldCheck className="w-24 h-24" />
                                </div>
                            </div>
                        ) : (
                            <>
                                <Button size="lg" onClick={handleBackup} className="w-full">
                                    <Archive className="h-4 w-4 mr-2" />
                                    Backup Now
                                </Button>

                                {/* Smart Incremental Toggle */}
                                <div className="flex items-center justify-between p-3 bg-muted/50 rounded-lg">
                                    <div className="flex items-center gap-3">
                                        <Zap className="h-4 w-4 text-yellow-500" />
                                        <div>
                                            <Label htmlFor="incremental" className="text-sm font-medium cursor-pointer">Smart Incremental</Label>
                                            <p className="text-xs text-muted-foreground">Only backup changed files</p>
                                        </div>
                                    </div>
                                    <Switch
                                        id="incremental"
                                        checked={incrementalEnabled}
                                        onCheckedChange={handleIncrementalToggle}
                                    />
                                </div>

                                {/* More Options Collapsible */}
                                <div>
                                    <button
                                        onClick={() => setShowAdvanced(!showAdvanced)}
                                        className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground transition-colors w-full justify-center py-1"
                                    >
                                        {showAdvanced ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />}
                                        {showAdvanced ? 'Less options' : 'More options'}
                                    </button>

                                    {showAdvanced && (
                                        <div className="mt-2 space-y-2 p-3 border rounded-lg bg-background">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <Archive className="h-3 w-3 text-muted-foreground" />
                                                    <span className="text-xs">Force full backup</span>
                                                </div>
                                                <Switch
                                                    checked={forceFullBackup}
                                                    onCheckedChange={(v) => { setForceFullBackup(v); if (v) setDbOnlyBackup(false); }}
                                                    disabled={!incrementalEnabled}
                                                />
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <Database className="h-3 w-3 text-muted-foreground" />
                                                    <span className="text-xs">Database only</span>
                                                </div>
                                                <Switch
                                                    checked={dbOnlyBackup}
                                                    onCheckedChange={(v) => { setDbOnlyBackup(v); if (v) setForceFullBackup(false); }}
                                                />
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>

                <Card className="lg:col-span-4 md:col-span-2">
                    <CardContent className="p-0">
                        <div className="grid md:grid-cols-2 divide-y md:divide-y-0 md:divide-x">
                            {/* Safe Update */}
                            <div className="p-4 flex flex-col">
                                <div className="flex items-center justify-between mb-2">
                                    <div className="flex items-center gap-2">
                                        <ArrowUpCircle className="h-4 w-4 text-blue-500" />
                                        <span className="text-sm font-medium">Safe Update</span>
                                    </div>
                                    {window.sbwpData.isPro && <span className="text-[10px] bg-blue-500/20 text-blue-600 px-2 py-0.5 rounded-full font-bold">PRO</span>}
                                </div>
                                <p className="text-xs text-muted-foreground flex-1">Test updates safely in a sandbox before applying to production.</p>
                                <Button size="sm" className="mt-3 w-full bg-blue-600 hover:bg-blue-500 text-white" onClick={() => {
                                    if (window.sbwpData.isPro) {
                                        setShowSafeUpdate(true)
                                    } else {
                                        window.location.href = '/wp-admin/update-core.php'
                                    }
                                }}>
                                    {window.sbwpData.isPro ? 'Test Updates' : 'Go to Updates'}
                                </Button>
                            </div>
                            {/* Conflict Scanner */}
                            <div className="p-4 flex flex-col">
                                <div className="flex items-center justify-between mb-2">
                                    <div className="flex items-center gap-2">
                                        <Search className="h-4 w-4 text-orange-500" />
                                        <span className="text-sm font-medium">Conflict Scanner</span>
                                    </div>
                                    {window.sbwpData.isPro && <span className="text-[10px] bg-orange-500/20 text-orange-500 px-2 py-0.5 rounded-full font-bold">PRO</span>}
                                </div>
                                <p className="text-xs text-muted-foreground flex-1">Find buggy plugins auto-magically.</p>
                                <Button size="sm" variant="outline" className="mt-3 w-full" onClick={() => window.sbwpData.isPro && setShowScanner(true)}>
                                    {window.sbwpData.isPro ? 'Start Scan' : 'Get Pro'}
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <BackupsList refreshTrigger={refreshBackups} />
        </div >
    )
}
