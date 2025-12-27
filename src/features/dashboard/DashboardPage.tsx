import { useEffect, useState } from "react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { ShieldCheck, HardDrive, RefreshCw, Loader2, ArrowUpCircle, Database, Archive, CheckCircle, Search } from "lucide-react"
import { Progress } from "@/components/ui/progress"
import { fetchStats, createBackup, fetchProgress, type Stats } from "@/lib/api"
import { useToast } from "@/components/ui/use-toast"
import { Toaster } from "@/components/ui/toaster"
import { SafeUpdateDialog } from "./SafeUpdateDialog"
import { ConflictScannerDialog } from "./ConflictScannerDialog"
import { BackupsList } from "./BackupsList"

interface DashboardPageProps {
    onNavigate: (view: 'dashboard' | 'settings' | 'schedules') => void
}

export function DashboardPage({ onNavigate }: DashboardPageProps) {
    const [stats, setStats] = useState<Stats | null>(null)
    const [loading, setLoading] = useState(true)
    const [backingUp, setBackingUp] = useState(false)
    const [showSafeUpdate, setShowSafeUpdate] = useState(false)
    const [showScanner, setShowScanner] = useState(false)
    const [refreshBackups, setRefreshBackups] = useState(0)
    const [progress, setProgress] = useState(0)
    const [progressMsg, setProgressMsg] = useState('')
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
    }, [])

    useEffect(() => {
        let interval: NodeJS.Timeout
        if (backingUp) {
            interval = setInterval(async () => {
                try {
                    const p = await fetchProgress()
                    setProgress(p.percent)
                    setProgressMsg(p.message)
                } catch { }
            }, 1000)
        } else {
            setProgress(0)
            setProgressMsg('')
        }
        return () => clearInterval(interval)
    }, [backingUp])

    const handleBackup = async () => {
        setBackingUp(true)
        setProgress(1)
        try {
            let result = await createBackup(false)

            if (result.code) throw new Error(result.message); // Handle initial error

            while (result.status === 'processing') {
                // If provided in response, use it (optional, but polling is main source)
                if (result.percent) setProgress(result.percent)
                if (result.message) setProgressMsg(result.message)

                // Resume
                result = await createBackup(true)
                if (result.code) throw new Error(result.message); // Handle resume error
            }

            toast({
                title: "Backup Complete",
                description: "Your local backup was created successfully.",
            })
            await loadStats()
            setRefreshBackups(prev => prev + 1)
        } catch (error) {
            console.error(error)
            toast({
                title: "Backup Failed",
                description: "There was an error creating your backup.",
                variant: "destructive"
            })
        } finally {
            setBackingUp(false)
        }
    }

    if (loading) {
        return <div className="p-8 flex justify-center"><Loader2 className="h-8 w-8 animate-spin text-muted-foreground" /></div>
    }

    return (
        <div className="space-y-6">
            <Toaster />
            <SafeUpdateDialog open={showSafeUpdate} onOpenChange={setShowSafeUpdate} />
            <ConflictScannerDialog open={showScanner} onOpenChange={setShowScanner} />

            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
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

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Quick Actions</CardTitle>
                        <RefreshCw className="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2">
                        {backingUp ? (
                            <div className="space-y-2 w-full">
                                <div className="flex justify-between text-xs text-muted-foreground items-center">
                                    <div className="flex items-center gap-2">
                                        {(() => {
                                            const msg = (progressMsg || '').toLowerCase();
                                            if (progress >= 100) return <CheckCircle className="h-4 w-4 text-emerald-500" />;
                                            if (msg.includes('exporting')) return <Database className="h-4 w-4 text-blue-500 animate-pulse" />;
                                            if (msg.includes('scanning')) return <Search className="h-4 w-4 text-yellow-500 animate-pulse" />;
                                            if (msg.includes('archiving')) return <Archive className="h-4 w-4 text-orange-500 animate-pulse" />;
                                            if (msg.includes('finalizing')) return <HardDrive className="h-4 w-4 text-purple-500 animate-pulse" />;
                                            return <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />;
                                        })()}
                                        <span>{progressMsg || 'Starting...'}</span>
                                    </div>
                                    <span>{progress}%</span>
                                </div>
                                <Progress value={progress} className="h-2" />
                            </div>
                        ) : (
                            <div className="flex gap-2">
                                <Button size="sm" onClick={handleBackup}>
                                    Backup Now
                                </Button>
                                <Button size="sm" variant="outline" onClick={() => onNavigate('settings')}>Settings</Button>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Safe Update</CardTitle>
                        <ArrowUpCircle className="h-4 w-4 text-blue-500" />
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">Updates</div>
                        <p className="text-xs text-muted-foreground mt-1">
                            Test updates in sandbox
                        </p>
                        <Button size="sm" className="mt-4 w-full h-8 text-xs bg-blue-600 hover:bg-blue-500 text-white" onClick={async () => {
                            if (window.sbwpData.isPro) {
                                setShowSafeUpdate(true)
                            } else {
                                window.location.href = '/wp-admin/update-core.php'
                            }
                        }}>
                            {window.sbwpData.isPro ? 'Test Updates' : 'Go to Updates'}
                        </Button>
                    </CardContent>
                </Card>

                <Card className={window.sbwpData.isPro ? "border-orange-500/50 bg-orange-500/5" : "opacity-80"}>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Conflict Scanner</CardTitle>
                        {window.sbwpData.isPro ? (
                            <span className="text-[10px] bg-orange-500/20 text-orange-500 px-2 py-0.5 rounded-full font-bold">PRO ACTIVE</span>
                        ) : (
                            <span className="text-[10px] bg-slate-100 text-slate-500 px-2 py-0.5 rounded-full font-bold">LOCKED</span>
                        )}
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">Diagnose</div>
                        <p className="text-xs text-muted-foreground mt-1">
                            Find buggy plugins auto-magically
                        </p>
                        <Button size="sm" variant={window.sbwpData.isPro ? "outline" : "secondary"} className="mt-4 w-full h-8 text-xs"
                            onClick={() => window.sbwpData.isPro && setShowScanner(true)}>
                            {window.sbwpData.isPro ? 'Start Scan' : 'Get Pro'}
                        </Button>
                    </CardContent>
                </Card>
            </div>

            <BackupsList refreshTrigger={refreshBackups} />
        </div>
    )
}
