import { useState, useEffect } from "react"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { ScrollArea } from "@/components/ui/scroll-area"
import { Loader2, Download, Folder, File, FileCode, FileText, Database, ChevronRight, ChevronDown } from "lucide-react"
import { getBackupFiles, downloadBackup, type BackupFile, type Backup } from "@/lib/api"
import { useToast } from "@/components/ui/use-toast"

interface BackupBrowserDialogProps {
    backup: Backup | null
    open: boolean
    onOpenChange: (open: boolean) => void
}

function formatBytes(bytes: number): string {
    if (bytes === 0) return '0 B'
    const k = 1024
    const sizes = ['B', 'KB', 'MB', 'GB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

function getFileIcon(file: BackupFile) {
    if (file.type === 'folder') return <Folder className="h-4 w-4 text-yellow-500" />
    if (file.category === 'database' || file.name.endsWith('.sql')) return <Database className="h-4 w-4 text-blue-500" />
    if (file.name.endsWith('.php')) return <FileCode className="h-4 w-4 text-purple-500" />
    if (file.name.endsWith('.js') || file.name.endsWith('.ts')) return <FileCode className="h-4 w-4 text-yellow-600" />
    if (file.name.endsWith('.css')) return <FileCode className="h-4 w-4 text-blue-400" />
    if (file.name.endsWith('.json') || file.name.endsWith('.txt') || file.name.endsWith('.md')) return <FileText className="h-4 w-4 text-gray-500" />
    return <File className="h-4 w-4 text-gray-400" />
}

export function BackupBrowserDialog({ backup, open, onOpenChange }: BackupBrowserDialogProps) {
    const [files, setFiles] = useState<BackupFile[]>([])
    const [loading, setLoading] = useState(false)
    const [downloading, setDownloading] = useState<string | null>(null)
    const [expanded, setExpanded] = useState<Set<string>>(new Set())
    const { toast } = useToast()

    useEffect(() => {
        if (open && backup) {
            loadFiles()
        }
    }, [open, backup])

    const loadFiles = async () => {
        if (!backup) return
        setLoading(true)
        try {
            const data = await getBackupFiles(backup.id)
            setFiles(data.files)
            // Auto-expand first level
            const firstLevel = new Set<string>()
            data.files.forEach(f => {
                if (f.depth === 0 && f.type === 'folder') {
                    firstLevel.add(f.path)
                }
            })
            setExpanded(firstLevel)
        } catch (e) {
            console.error(e)
            toast({ title: "Error", description: "Failed to load backup files", variant: "destructive" })
        } finally {
            setLoading(false)
        }
    }

    const handleDownload = async (path: string) => {
        if (!backup) return
        setDownloading(path)
        try {
            const result = await downloadBackup(backup.id, path)
            // Trigger download
            const a = document.createElement('a')
            a.href = result.download_url
            a.download = result.filename
            document.body.appendChild(a)
            a.click()
            document.body.removeChild(a)
            toast({ title: "Download Started", description: result.filename })
        } catch (e) {
            console.error(e)
            toast({ title: "Error", description: "Failed to download file", variant: "destructive" })
        } finally {
            setDownloading(null)
        }
    }

    const toggleExpand = (path: string) => {
        const newExpanded = new Set(expanded)
        if (newExpanded.has(path)) {
            newExpanded.delete(path)
        } else {
            newExpanded.add(path)
        }
        setExpanded(newExpanded)
    }

    const isVisible = (file: BackupFile) => {
        if (file.depth === 0) return true
        // Check if all parent folders are expanded
        const parts = file.path.split('/').filter(Boolean)
        for (let i = 0; i < parts.length - 1; i++) {
            const parentPath = '/' + parts.slice(0, i + 1).join('/')
            if (!expanded.has(parentPath)) return false
        }
        return true
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-2xl max-h-[80vh] flex flex-col">
                <DialogHeader>
                    <DialogTitle>Browse Backup</DialogTitle>
                </DialogHeader>

                <div className="flex justify-end mb-2">
                    <Button
                        size="sm"
                        onClick={() => handleDownload('/')}
                        disabled={downloading === '/'}
                    >
                        {downloading === '/' ? (
                            <Loader2 className="h-4 w-4 animate-spin mr-2" />
                        ) : (
                            <Download className="h-4 w-4 mr-2" />
                        )}
                        Download All
                    </Button>
                </div>

                {loading ? (
                    <div className="flex justify-center items-center py-12">
                        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
                    </div>
                ) : (
                    <ScrollArea className="h-[400px] border rounded-lg">
                        <div className="p-2">
                            {files.filter(isVisible).map((file) => (
                                <div
                                    key={file.path}
                                    className="flex items-center gap-2 py-1.5 px-2 hover:bg-muted/50 rounded group"
                                    style={{ paddingLeft: `${file.depth * 20 + 8}px` }}
                                >
                                    {file.type === 'folder' && file.hasChildren ? (
                                        <button
                                            onClick={() => toggleExpand(file.path)}
                                            className="p-0.5 hover:bg-muted rounded"
                                        >
                                            {expanded.has(file.path) ? (
                                                <ChevronDown className="h-3 w-3 text-muted-foreground" />
                                            ) : (
                                                <ChevronRight className="h-3 w-3 text-muted-foreground" />
                                            )}
                                        </button>
                                    ) : (
                                        <div className="w-4" />
                                    )}

                                    {getFileIcon(file)}

                                    <span className="flex-1 text-sm truncate">{file.name}</span>

                                    {file.type === 'file' && (
                                        <span className="text-xs text-muted-foreground">
                                            {formatBytes(file.size)}
                                        </span>
                                    )}

                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="h-6 w-6 opacity-0 group-hover:opacity-100 transition-opacity"
                                        onClick={() => handleDownload(file.path)}
                                        disabled={downloading === file.path}
                                    >
                                        {downloading === file.path ? (
                                            <Loader2 className="h-3 w-3 animate-spin" />
                                        ) : (
                                            <Download className="h-3 w-3" />
                                        )}
                                    </Button>
                                </div>
                            ))}

                            {files.length === 0 && (
                                <div className="text-center py-8 text-muted-foreground">
                                    <File className="h-8 w-8 mx-auto mb-2 opacity-30" />
                                    <p>No files found in this backup</p>
                                </div>
                            )}
                        </div>
                    </ScrollArea>
                )}
            </DialogContent>
        </Dialog>
    )
}
