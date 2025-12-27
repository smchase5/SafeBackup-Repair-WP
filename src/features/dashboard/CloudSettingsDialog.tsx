import { useEffect, useState } from "react"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { getCloudProviders, connectProvider, type CloudProvider } from "@/lib/api"
import { Loader2, Cloud, CheckCircle2 } from "lucide-react"

interface CloudSettingsDialogProps {
    open: boolean
    onOpenChange: (open: boolean) => void
}

export function CloudSettingsDialog({ open, onOpenChange }: CloudSettingsDialogProps) {
    const [providers, setProviders] = useState<CloudProvider[]>([])
    const [loading, setLoading] = useState(false)
    const [processing, setProcessing] = useState<string | null>(null)

    const loadProviders = () => {
        setLoading(true)
        getCloudProviders()
            .then(setProviders)
            .catch(console.error)
            .finally(() => setLoading(false))
    }

    useEffect(() => {
        if (open) {
            loadProviders()
        }
    }, [open])

    const handleToggle = async (provider: CloudProvider) => {
        setProcessing(provider.id)
        try {
            await connectProvider(provider.id, provider.connected ? 'disconnect' : 'connect')
            loadProviders()
        } catch (error) {
            console.error(error)
        } finally {
            setProcessing(null)
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>Cloud Storage Settings</DialogTitle>
                    <DialogDescription>
                        Connect your cloud storage provider to enable automatic off-site backups.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 py-4">
                    {loading && providers.length === 0 ? (
                        <div className="flex justify-center p-4">
                            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                        </div>
                    ) : (
                        <div className="space-y-2">
                            {providers.length === 0 && <p className="text-sm text-muted-foreground text-center">No providers available.</p>}

                            {providers.map(provider => (
                                <div key={provider.id} className="flex items-center justify-between rounded-lg border p-3">
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                            <Cloud className="h-4 w-4 text-slate-500" />
                                        </div>
                                        <div className="grid gap-0.5">
                                            <div className="font-medium text-sm">{provider.name}</div>
                                            <div className="text-[10px] text-muted-foreground uppercase">{provider.connected ? 'Connected' : 'Not Connected'}</div>
                                        </div>
                                    </div>
                                    <Button
                                        size="sm"
                                        variant={provider.connected ? "outline" : "default"}
                                        onClick={() => handleToggle(provider)}
                                        disabled={processing === provider.id || (provider.id !== 'gdrive' && provider.id !== 'aws_s3' && provider.id !== 'dropbox')} // Keeping placeholders disabled if needed, but for now enabling connect
                                    >
                                        {processing === provider.id ? (
                                            <Loader2 className="h-4 w-4 animate-spin" />
                                        ) : provider.connected ? (
                                            <div className="flex items-center gap-2 group">
                                                <CheckCircle2 className="h-4 w-4 text-green-500 group-hover:hidden" />
                                                <span className="hidden group-hover:inline text-xs text-red-500">Disconnect</span>
                                            </div>
                                        ) : (
                                            'Connect'
                                        )}
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    )
}
