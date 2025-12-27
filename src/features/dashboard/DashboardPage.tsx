import { useEffect, useState } from "react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { ShieldCheck, HardDrive, RefreshCw, Loader2, ArrowUpCircle } from "lucide-react"
import { fetchStats, createBackup, type Stats } from "@/lib/api"
import { useToast } from "@/components/ui/use-toast"
import { Toaster } from "@/components/ui/toaster"
import { CloudSettingsDialog } from "./CloudSettingsDialog"
import { SafeUpdateDialog } from "./SafeUpdateDialog"
import { ConflictScannerDialog } from "./ConflictScannerDialog"

interface DashboardPageProps {
    onNavigate: (view: 'dashboard' | 'settings' | 'schedules') => void
}

export function DashboardPage({ onNavigate }: DashboardPageProps) {
    const [stats, setStats] = useState<Stats | null>(null)
    const [loading, setLoading] = useState(true)
    const [backingUp, setBackingUp] = useState(false)
    const [showCloudSettings, setShowCloudSettings] = useState(false)
    const [showSafeUpdate, setShowSafeUpdate] = useState(false)
    const [showScanner, setShowScanner] = useState(false)
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

    const handleBackup = async () => {
        setBackingUp(true)
        try {
            await createBackup()
            toast({
                title: "Backup Complete",
                description: "Your local backup was created successfully.",
            })
            await loadStats()
        } catch (error) {
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
            <CloudSettingsDialog open={showCloudSettings} onOpenChange={setShowCloudSettings} />
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
                    <CardContent className="flex gap-2">
                        <Button size="sm" onClick={handleBackup} disabled={backingUp}>
                            {backingUp ? (
                                <>
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    Backing Up...
                                </>
                            ) : (
                                'Backup Now'
                            )}
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => onNavigate('settings')}>Settings</Button>
                    </CardContent>
                </Card>

                <Card className={window.sbwpData.isPro ? "border-emerald-500/50 bg-emerald-500/5" : "opacity-80"}>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">
                            Cloud Storage
                        </CardTitle>
                        {window.sbwpData.isPro ? (
                            <span className="text-[10px] bg-emerald-500/20 text-emerald-500 px-2 py-0.5 rounded-full font-bold">PRO ACTIVE</span>
                        ) : (
                            <span className="text-[10px] bg-slate-100 text-slate-500 px-2 py-0.5 rounded-full font-bold">FREE</span>
                        )}
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">
                            {window.sbwpData.isPro ? 'Not Connected' : 'Locked'}
                        </div>
                        <p className="text-xs text-muted-foreground mt-1">
                            {window.sbwpData.isPro
                                ? 'Configure Google Drive / S3'
                                : 'Upgrade to enable Cloud Backups'}
                        </p>
                        {window.sbwpData.isPro ? (
                            <Button size="sm" variant="outline" className="mt-4 w-full h-8 text-xs" onClick={() => setShowCloudSettings(true)}>
                                Configure
                            </Button>
                        ) : (
                            <Button size="sm" variant="secondary" className="mt-4 w-full h-8 text-xs">
                                Get Pro
                            </Button>
                        )}
                    </CardContent>
                </Card>

                {window.sbwpData.isPro && (
                    <Card className="border-emerald-500/50 bg-emerald-500/5">
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Scheduled Backups</CardTitle>
                            <span className="text-[10px] bg-emerald-500/20 text-emerald-500 px-2 py-0.5 rounded-full font-bold">PRO ACTIVE</span>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">Manage</div>
                            <p className="text-xs text-muted-foreground mt-1">
                                Automated daily/weekly backups
                            </p>
                            <Button size="sm" variant="outline" className="mt-4 w-full h-8 text-xs" onClick={() => onNavigate('schedules')}>
                                Configure Schedules
                            </Button>
                        </CardContent>
                    </Card>
                )}

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
                                // Free fallback: just redirect
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

            <Card className="col-span-3">
                <CardHeader>
                    <CardTitle>Welcome to SafeBackup</CardTitle>
                    <CardDescription>
                        {stats?.count === 0
                            ? "Your site is currently unprotected. Create a backup to get started."
                            : "Your site is protected. You can create a new backup at any time."
                        }
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="rounded-md bg-muted p-4">
                        <p className="text-sm">
                            {stats?.count === 0
                                ? "Ready to protect your site? Click the \"Backup Now\" button above to create your first local backup."
                                : `You have ${stats?.count} local backups available.`
                            }
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>
    )
}
