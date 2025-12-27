import { useState, useEffect } from 'react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Badge } from "@/components/ui/badge"
import { Switch } from "@/components/ui/switch"
import { ScrollArea } from "@/components/ui/scroll-area"
import { Loader2, RefreshCw, AlertTriangle, Sparkles } from "lucide-react"
import { cn } from "@/lib/utils"
import { AIAnalysisDialog } from "./AIAnalysisDialog"

declare global {
    interface Window {
        sbwpRecoveryData: {
            ajaxUrl: string;
            restUrl: string;
            nonce: string;
        }
    }
}

interface Plugin {
    file: string;
    name: string;
    version: string;
    active: boolean;
}

export function RecoveryApp() {
    const [activeTab, setActiveTab] = useState("restore")
    const [restoring, setRestoring] = useState(false)

    // Plugin Manager State
    const [plugins, setPlugins] = useState<Plugin[]>([])
    const [loadingPlugins, setLoadingPlugins] = useState(false)

    // Log Reader State
    const [logContent, setLogContent] = useState("")
    const [logSize, setLogSize] = useState("")
    const [loadingLog, setLoadingLog] = useState(false)
    const [showAI, setShowAI] = useState(false)

    useEffect(() => {
        if (activeTab === 'plugins') {
            loadPlugins()
        } else if (activeTab === 'logs') {
            loadLog()
        }
    }, [activeTab])

    const loadPlugins = async () => {
        setLoadingPlugins(true)
        const params = new URLSearchParams()
        params.append('action', 'sbwp_recovery_get_plugins')
        params.append('nonce', window.sbwpRecoveryData.nonce)

        try {
            const res = await fetch(window.sbwpRecoveryData.ajaxUrl, {
                method: 'POST',
                body: params
            })
            const data = await res.json()
            if (data.success) {
                setPlugins(data.data)
            }
        } catch (e) {
            console.error(e)
        } finally {
            setLoadingPlugins(false)
        }
    }

    const togglePlugin = async (file: string, isActive: boolean) => {
        // Optimistic update
        setPlugins(prev => prev.map(p => p.file === file ? { ...p, active: !isActive } : p))

        const params = new URLSearchParams()
        params.append('action', 'sbwp_recovery_toggle_plugin')
        params.append('nonce', window.sbwpRecoveryData.nonce)
        params.append('plugin', file)
        params.append('plugin_action', isActive ? 'deactivate' : 'activate')

        await fetch(window.sbwpRecoveryData.ajaxUrl, {
            method: 'POST',
            body: params
        })
    }

    const loadLog = async () => {
        setLoadingLog(true)
        const params = new URLSearchParams()
        params.append('action', 'sbwp_recovery_get_log')
        params.append('nonce', window.sbwpRecoveryData.nonce)

        try {
            const res = await fetch(window.sbwpRecoveryData.ajaxUrl, {
                method: 'POST',
                body: params
            })
            const data = await res.json()
            if (data.success) {
                setLogContent(data.data.content)
                setLogSize(data.data.size)
            } else {
                setLogContent(data.data) // Error message
            }
        } catch (e) {
            setLogContent("Failed to load logs.")
        } finally {
            setLoadingLog(false)
        }
    }

    const handleRestore = async () => {
        if (!confirm("Are you sure? This will overwrite your database and files.")) return

        setRestoring(true)
        const params = new URLSearchParams()
        params.append('action', 'sbwp_recovery_restore')
        params.append('nonce', window.sbwpRecoveryData.nonce)
        params.append('backup_id', '1') // Placeholder

        try {
            const res = await fetch(window.sbwpRecoveryData.ajaxUrl, {
                method: 'POST',
                body: params
            })
            const data = await res.json()
            if (data.success) {
                alert("Restore Complete!")
                window.location.reload()
            } else {
                alert("Error: " + data.data)
            }
        } catch (e) {
            alert("Network Error")
        } finally {
            setRestoring(false)
        }
    }

    return (
        <div className="min-h-screen bg-slate-950 text-slate-50 p-8 flex items-start justify-center font-sans antialiased selection:bg-emerald-500/30">
            <div className="w-full max-w-4xl space-y-6">

                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
                            <ShieldCheck className="h-6 w-6 text-emerald-500" />
                            Recovery Portal
                        </h1>
                        <p className="text-slate-400">Safe Mode Active • Plugins Filtered</p>
                    </div>
                    <Button variant="outline" className="border-slate-800 bg-slate-900 text-slate-200 hover:bg-slate-800 hover:text-white" onClick={() => window.location.href = '/wp-admin'}>
                        Exit to WP Admin
                    </Button>
                </div>

                <Tabs defaultValue="restore" onValueChange={setActiveTab} className="w-full">
                    <TabsList className="grid w-full grid-cols-3 bg-slate-900 border border-slate-800">
                        <TabsTrigger value="restore">Restore Backup</TabsTrigger>
                        <TabsTrigger value="plugins">Plugin Manager</TabsTrigger>
                        <TabsTrigger value="logs">Debug Logs</TabsTrigger>
                    </TabsList>

                    <TabsContent value="restore">
                        <Card className="bg-slate-900 border-slate-800 text-slate-200">
                            <CardHeader>
                                <CardTitle className="text-white">System Restore</CardTitle>
                                <CardDescription className="text-slate-400">
                                    Restore your site to a previous working state.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="rounded-lg border border-yellow-900/50 bg-yellow-900/10 p-4 text-yellow-200 flex items-start gap-4">
                                    <AlertTriangle className="h-5 w-5 shrink-0" />
                                    <div className="text-sm">
                                        <p className="font-bold mb-1">Warning: Destructive Action</p>
                                        <p>Restoring will overwrite your database and files. Any changes made since the backup will be lost.</p>
                                    </div>
                                </div>

                                <div className="flex items-center justify-between p-4 border border-slate-800 rounded-lg bg-slate-950/50">
                                    <div>
                                        <div className="font-medium text-white">Latest Local Backup</div>
                                        <div className="text-sm text-slate-500">Dec 26, 2025 • 24.5 MB</div>
                                    </div>
                                    <Button onClick={handleRestore} disabled={restoring} className="bg-emerald-600 hover:bg-emerald-500 text-white">
                                        {restoring ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <RefreshCw className="mr-2 h-4 w-4" />}
                                        Restore Now
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="plugins">
                        <Card className="bg-slate-900 border-slate-800 text-slate-200">
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <CardTitle className="text-white">Plugin Manager</CardTitle>
                                    <Button variant="ghost" size="sm" onClick={loadPlugins} disabled={loadingPlugins}>
                                        <RefreshCw className={cn("h-4 w-4", loadingPlugins && "animate-spin")} />
                                    </Button>
                                </div>
                                <CardDescription className="text-slate-400">
                                    Disable problematic plugins to regain access to your site.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <ScrollArea className="h-[400px] pr-4">
                                    <div className="space-y-2">
                                        {plugins.map(plugin => (
                                            <div key={plugin.file} className="flex items-center justify-between p-3 rounded-lg border border-slate-800 bg-slate-950/30">
                                                <div>
                                                    <div className="font-medium text-slate-200">{plugin.name}</div>
                                                    <div className="text-xs text-slate-500">{plugin.file}</div>
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    <Badge className={plugin.active ? "bg-emerald-500/20 text-emerald-500 hover:bg-emerald-500/30" : "bg-slate-800 text-slate-500 hover:bg-slate-700"}>
                                                        {plugin.active ? "Active" : "Inactive"}
                                                    </Badge>
                                                    <Switch
                                                        checked={plugin.active}
                                                        onCheckedChange={() => togglePlugin(plugin.file, plugin.active)}
                                                    />
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </ScrollArea>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="logs">
                        <Card className="bg-slate-900 border-slate-800 text-slate-200">
                            <AIAnalysisDialog open={showAI} onOpenChange={setShowAI} logSnippet={logContent.slice(0, 2000)} />

                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <CardTitle className="text-white">Debug Log</CardTitle>
                                    <div className="flex gap-2">
                                        <Button variant="outline" size="sm" className="border-indigo-500/50 text-indigo-400 hover:bg-indigo-500/10" onClick={() => setShowAI(true)} disabled={!logContent}>
                                            <Sparkles className="mr-2 h-4 w-4" />
                                            Analyze with AI
                                        </Button>
                                        <Button variant="ghost" size="sm" onClick={loadLog} disabled={loadingLog}>
                                            <RefreshCw className={cn("h-4 w-4", loadingLog && "animate-spin")} />
                                        </Button>
                                    </div>
                                </div>
                                <CardDescription className="text-slate-400">
                                    Viewing {logSize || 'file'}. Last 500 lines.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <ScrollArea className="h-[500px] w-full rounded-md border border-slate-800 bg-slate-950 p-4">
                                    <pre className="text-xs font-mono text-slate-300 whitespace-pre-wrap">
                                        {loadingLog ? "Loading..." : (logContent || "debug.log is empty or not found.")}
                                    </pre>
                                </ScrollArea>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </div>
    )
}

function ShieldCheck(props: any) {
    return (
        <svg
            {...props}
            xmlns="http://www.w3.org/2000/svg"
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10" />
            <path d="m9 12 2 2 4-4" />
        </svg>
    )
}
