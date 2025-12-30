import { useState, useEffect } from "react"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Label } from "@/components/ui/label"
import { Loader2, AlertTriangle, ShieldCheck, Package } from "lucide-react"
import { getBackupContents, restoreBackup, type BackupContent, type Backup } from "@/lib/api"
import { useToast } from "@/components/ui/use-toast"

interface RestoreDialogProps {
    backup: Backup | null
    open: boolean
    onOpenChange: (open: boolean) => void
    onComplete: () => void
}

function formatBytes(bytes: string | number) {
    const b = typeof bytes === 'string' ? parseInt(bytes) : bytes;
    if (b === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(b) / Math.log(k));
    return parseFloat((b / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

export function RestoreDialog({ backup, open, onOpenChange, onComplete }: RestoreDialogProps) {
    const [step, setStep] = useState<'initial' | 'select' | 'restoring'>('initial')
    const [restoreType, setRestoreType] = useState<'full' | 'partial'>('full')
    const [loading, setLoading] = useState(false)
    const [contents, setContents] = useState<BackupContent | null>(null)
    const [selectedPlugins, setSelectedPlugins] = useState<string[]>([])
    const [selectedThemes, setSelectedThemes] = useState<string[]>([])
    const { toast } = useToast()

    // Reset state when dialog opens
    useEffect(() => {
        if (open) {
            setStep('initial')
            setRestoreType('full')
            setLoading(false)
            setContents(null)
            setSelectedPlugins([])
            setSelectedThemes([])
        }
    }, [open])

    const fetchContents = async () => {
        if (!backup) return
        setLoading(true)
        try {
            const data = await getBackupContents(backup.id)
            setContents(data)
            setStep('select')
        } catch (e) {
            toast({
                title: "Error",
                description: "Failed to load backup contents.",
                variant: "destructive"
            })
        } finally {
            setLoading(false)
        }
    }

    const handleRestore = async () => {
        if (!backup) return
        setStep('restoring')
        try {
            if (restoreType === 'full') {
                await restoreBackup(backup.id)
            } else {
                await restoreBackup(backup.id, {
                    plugins: selectedPlugins,
                    themes: selectedThemes
                })
            }
            toast({
                title: "Restore Complete",
                description: "The backup has been restored successfully.",
            })
            onComplete()
            onOpenChange(false)
            setTimeout(() => window.location.reload(), 1500)
        } catch (e: any) {
            setStep('initial')
            toast({
                title: "Restore Failed",
                description: e.message || "An error occurred during restore.",
                variant: "destructive"
            })
        }
    }

    const togglePlugin = (slug: string) => {
        setSelectedPlugins(prev =>
            prev.includes(slug) ? prev.filter(p => p !== slug) : [...prev, slug]
        )
    }

    const toggleTheme = (slug: string) => {
        setSelectedThemes(prev =>
            prev.includes(slug) ? prev.filter(t => t !== slug) : [...prev, slug]
        )
    }

    if (!backup) return null

    return (
        <Dialog open={open} onOpenChange={(val) => !loading && onOpenChange(val)}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Restore Backup</DialogTitle>
                    <DialogDescription>
                        Created on {backup.created_at} • {formatBytes(backup.size_bytes)}
                    </DialogDescription>
                </DialogHeader>

                {step === 'initial' && (
                    <div className="grid gap-6 py-4">
                        <div className="rounded-lg border border-red-500/50 bg-red-500/10 p-4 text-red-900 dark:text-red-100 flex gap-3">
                            <AlertTriangle className="h-5 w-5 text-red-600 dark:text-red-400 mt-0.5" />
                            <div>
                                <h5 className="font-medium mb-1">Warning</h5>
                                <div className="text-sm opacity-90">
                                    Restoring will overwrite existing files and database. This action cannot be undone.
                                </div>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div
                                className={`border-2 rounded-lg p-4 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors ${restoreType === 'full' ? 'border-primary bg-slate-50 dark:bg-slate-900' : 'border-muted'}`}
                                onClick={() => setRestoreType('full')}
                            >
                                <div className="flex items-center gap-2 mb-2">
                                    <ShieldCheck className="h-5 w-5 text-emerald-500" />
                                    <h3 className="font-semibold">Full Restore</h3>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Restore everything: Files, Database, Plugins, and Themes.
                                </p>
                            </div>
                            <div
                                className={`border-2 rounded-lg p-4 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors ${restoreType === 'partial' ? 'border-primary bg-slate-50 dark:bg-slate-900' : 'border-muted'}`}
                                onClick={() => setRestoreType('partial')}
                            >
                                <div className="flex items-center gap-2 mb-2">
                                    <Package className="h-5 w-5 text-blue-500" />
                                    <h3 className="font-semibold">Partial Restore</h3>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Select specific Plugins or Themes to restore. Database is NOT restored.
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                {step === 'select' && contents && (
                    <div className="py-4">
                        <Tabs defaultValue="plugins">
                            <TabsList>
                                <TabsTrigger value="plugins">Plugins ({contents.plugins.length})</TabsTrigger>
                                <TabsTrigger value="themes">Themes ({contents.themes.length})</TabsTrigger>
                            </TabsList>
                            <TabsContent value="plugins" className="mt-4 max-h-[300px] overflow-y-auto border rounded-md p-2">
                                {contents.plugins.length === 0 ? (
                                    <div className="text-center py-4 text-muted-foreground">No plugins found in backup.</div>
                                ) : (
                                    <div className="space-y-2">
                                        {contents.plugins.map(plugin => (
                                            <div key={plugin} className="flex items-center space-x-2 p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded">
                                                <input
                                                    type="checkbox"
                                                    id={`plugin-${plugin}`}
                                                    checked={selectedPlugins.includes(plugin)}
                                                    onChange={() => togglePlugin(plugin)}
                                                    className="h-4 w-4 rounded border-gray-300"
                                                />
                                                <Label htmlFor={`plugin-${plugin}`} className="flex-1 cursor-pointer font-medium">{plugin}</Label>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </TabsContent>
                            <TabsContent value="themes" className="mt-4 max-h-[300px] overflow-y-auto border rounded-md p-2">
                                {contents.themes.length === 0 ? (
                                    <div className="text-center py-4 text-muted-foreground">No themes found in backup.</div>
                                ) : (
                                    <div className="space-y-2">
                                        {contents.themes.map(theme => (
                                            <div key={theme} className="flex items-center space-x-2 p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded">
                                                <input
                                                    type="checkbox"
                                                    id={`theme-${theme}`}
                                                    checked={selectedThemes.includes(theme)}
                                                    onChange={() => toggleTheme(theme)}
                                                    className="h-4 w-4 rounded border-gray-300"
                                                />
                                                <Label htmlFor={`theme-${theme}`} className="flex-1 cursor-pointer font-medium">{theme}</Label>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </TabsContent>
                        </Tabs>

                        <div className="flex justify-between items-center mt-4 text-sm text-muted-foreground">
                            <span>Selected: {selectedPlugins.length} plugins, {selectedThemes.length} themes</span>
                        </div>
                    </div>
                )}

                {step === 'restoring' && (
                    <div className="py-8 text-center">
                        <Loader2 className="h-12 w-12 animate-spin text-primary mx-auto mb-4" />
                        <h3 className="text-lg font-medium mb-2">Restoring Backup...</h3>
                        <p className="text-muted-foreground max-w-sm mx-auto">
                            Please wait while we restore your {restoreType === 'full' ? 'site' : 'items'}. Do not close this window.
                        </p>
                    </div>
                )}

                <DialogFooter>
                    {step === 'initial' && (
                        <>
                            <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                            <Button onClick={() => restoreType === 'full' ? handleRestore() : fetchContents()} disabled={loading}>
                                {loading && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                                {restoreType === 'full' ? 'Start Restore' : 'Next Step'}
                            </Button>
                        </>
                    )}
                    {step === 'select' && (
                        <>
                            <Button variant="outline" onClick={() => setStep('initial')}>Back</Button>
                            <Button onClick={handleRestore} disabled={selectedPlugins.length === 0 && selectedThemes.length === 0}>
                                Start Restore ({selectedPlugins.length + selectedThemes.length} items)
                            </Button>
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    )
}
