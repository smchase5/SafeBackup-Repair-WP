// Declare global variables for TS
declare global {
    interface Window {
        sbwpData: {
            root: string;
            nonce: string;
            restUrl: string;
            isPro: boolean;
        }
    }
}

export interface Stats {
    count: number;
    total_size: string;
    last_backup: string;
}

export interface Backup {
    id: number;
    created_at: string;
    type: string;
    storage_location: string;
    size_bytes: string;
    status: string;
    is_locked?: number;
}

export async function fetchStats(): Promise<Stats> {
    const response = await fetch(`${window.sbwpData.restUrl}/stats`, {
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce
        }
    });
    if (!response.ok) throw new Error('Failed to fetch stats');
    return response.json();
}

export async function fetchBackups(): Promise<Backup[]> {
    const response = await fetch(`${window.sbwpData.restUrl}/backups`, {
        headers: { 'X-WP-Nonce': window.sbwpData.nonce }
    });
    if (!response.ok) throw new Error('Failed to fetch backups');
    return response.json();
}

export async function deleteBackup(id: string): Promise<any> {
    const response = await fetch(`${window.sbwpData.restUrl}/backups/${id}`, {
        method: 'DELETE',
        headers: { 'X-WP-Nonce': window.sbwpData.nonce }
    });
    if (!response.ok) {
        const data = await response.json();
        throw new Error(data.message || 'Failed to delete backup');
    }
    return response.json();
}

export interface BackupContent {
    plugins: string[];
    themes: string[];
}

// Backup File Browser types
export interface BackupFile {
    name: string;
    path: string;
    type: 'file' | 'folder';
    size: number;
    depth: number;
    category?: string;
    hasChildren?: boolean;
}

export interface BackupFilesResponse {
    id: number;
    created_at: string;
    type: string;
    size_bytes: string;
    files: BackupFile[];
}

export interface DownloadResponse {
    download_url: string;
    filename: string;
}

export async function getBackupFiles(id: number): Promise<BackupFilesResponse> {
    const response = await fetch(`${window.sbwpData.restUrl}/backups/${id}/files`, {
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce
        }
    });

    if (!response.ok) throw new Error('Failed to fetch backup files');
    return response.json();
}

export async function downloadBackup(id: number, path: string = '/'): Promise<DownloadResponse> {
    const response = await fetch(`${window.sbwpData.restUrl}/backups/${id}/download?path=${encodeURIComponent(path)}`, {
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce
        }
    });

    if (!response.ok) throw new Error('Failed to get download URL');
    return response.json();
}

export async function getBackupContents(id: number): Promise<BackupContent> {
    const response = await fetch(`${window.sbwpData.restUrl}/backups/${id}/contents`, {
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce
        }
    });

    if (!response.ok) throw new Error('Failed to fetch backup contents');
    return response.json();
}

export async function restoreBackup(id: number, items?: BackupContent): Promise<any> {
    const response = await fetch(`${window.sbwpData.restUrl}/backups/${id}/restore`, {
        method: 'POST',
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ items })
    });

    if (!response.ok) {
        const data = await response.json();
        throw new Error(data.message || 'Failed to restore backup');
    }
    return response.json();
}


export async function fetchProgress(): Promise<{ percent: number, message: string, active?: boolean, session_id?: string, status?: string }> {
    try {
        const response = await fetch(`${window.sbwpData.restUrl}/backup/progress?t=${Date.now()}`, {
            headers: { 'X-WP-Nonce': window.sbwpData.nonce }
        });
        if (!response.ok) return { percent: 0, message: 'Idle' };

        const text = await response.text();
        if (!text) return { percent: 0, message: 'Idle' };

        return JSON.parse(text);
    } catch (e) {
        return { percent: 0, message: 'Idle' };
    }
}

export async function cancelBackup(): Promise<any> {
    const response = await fetch(`${window.sbwpData.restUrl}/backup/cancel`, {
        method: 'POST',
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce
        }
    });

    if (!response.ok) throw new Error('Failed to cancel backup');
    return await response.json();
}

export interface CloudProvider {
    id: string;
    name: string;
    icon?: string;
    connected: boolean;
    user_info?: { email?: string; name?: string };
    global_creds?: boolean;
}

export async function getCloudProviders(): Promise<CloudProvider[]> {
    const response = await fetch(`${window.sbwpData.restUrl}/cloud/providers`, {
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce
        }
    });

    if (response.status === 404) return [];
    if (!response.ok) throw new Error('Failed to fetch cloud providers');
    return response.json();
}

export async function connectProvider(provider_id: string, action: 'connect' | 'disconnect' | 'prepare', data?: any) {
    const response = await fetch(`${window.sbwpData.root}wp-json/sbwp/v1/cloud/connect`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': window.sbwpData.nonce
        },
        body: JSON.stringify({ provider_id, action, ...data })
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.message || 'Failed to connect');
    return result;
}

export async function getCloudSettings() {
    const response = await fetch(`${window.sbwpData.root}wp-json/sbwp/v1/cloud/settings`, {
        headers: { 'X-WP-Nonce': window.sbwpData.nonce }
    });
    return response.json();
}

export async function updateCloudSettings(settings: { retention_count: number }) {
    await fetch(`${window.sbwpData.root}wp-json/sbwp/v1/cloud/settings`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': window.sbwpData.nonce
        },
        body: JSON.stringify(settings)
    });
}

export interface Settings {
    retention_limit: number;
    alert_email?: string;
    alerts_enabled?: boolean;
    incremental_enabled?: boolean;
}

export async function getSettings(): Promise<Settings> {
    const response = await fetch(`${window.sbwpData.restUrl}/settings`, {
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce
        }
    });
    if (!response.ok) throw new Error('Failed to fetch settings');
    return response.json();
}

export async function saveSettings(settings: Settings): Promise<any> {
    const response = await fetch(`${window.sbwpData.restUrl}/settings`, {
        method: 'POST',
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(settings)
    });
    if (!response.ok) throw new Error('Failed to save settings');
    return response.json();
}

export async function startBackup(type: 'full' | 'incremental' = 'full', sessionId?: string): Promise<any> {
    const response = await fetch(`${window.sbwpData.restUrl}/backup`, {
        method: 'POST',
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ type, session_id: sessionId })
    });

    if (!response.ok) throw new Error('Failed to start backup');
    return await response.json();
}

export async function createBackup(type: 'full' | 'incremental' | 'db_only' = 'full', resume = false, sessionId?: string): Promise<any> {
    const response = await fetch(`${window.sbwpData.restUrl}/backups`, {
        method: 'POST',
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ type, resume, session_id: sessionId })
    });

    const text = await response.text();
    if (!text) {
        console.error(`SBWP Fetch Error: Empty response. Status: ${response.status} ${response.statusText}`);
        throw new Error(`Empty response from server (Status ${response.status})`);
    }

    try {
        const data = JSON.parse(text);
        if (!response.ok) throw new Error(data.message || 'Failed to create backup');
        return data;
    } catch (e) {
        console.error('JSON Parse Error:', text);
        throw new Error('Invalid server response');
    }
}

// ===== Safe Update API =====

export interface AvailableUpdate {
    file?: string;
    slug?: string;
    name: string;
    current_version: string;
    new_version: string;
    requires_php?: string;
    requires?: string;
}

export interface AvailableUpdates {
    plugins: AvailableUpdate[];
    themes: AvailableUpdate[];
    core: { current_version: string; new_version: string } | null;
}

export interface SafeUpdateSession {
    id: number;
    clone_id: string;
    status: 'queued' | 'running' | 'completed' | 'failed';
    progress: {
        message: string;
        percent: number;
        updated_at: number;
    } | null;
    items: {
        plugins: string[];
        themes: string[];
        core: boolean;
    };
    result: SafeUpdateResult | null;
    error_message: string | null;
    created_at: string;
    updated_at: string;
}

export interface SafeUpdateResult {
    summary: {
        overall_status: 'safe' | 'risky' | 'unsafe';
        php_version: string;
        wp_version: string;
        started_at: string;
        finished_at: string;
    };
    items: {
        plugins: Array<{
            slug: string;
            name: string;
            from_version: string;
            to_version: string;
            status: 'safe' | 'risky' | 'unsafe' | 'pending';
            issues: Array<{ type: string; message: string }>;
        }>;
        themes: Array<{
            slug: string;
            name: string;
            status: string;
            issues: Array<{ type: string; message: string }>;
        }>;
    };
    health_checks: Array<{
        url: string;
        label: string;
        status_code: number;
        wsod_detected: boolean;
        errors_detected: string[];
    }>;
    logs: {
        new_entries: string[];
    };
}

export async function getAvailableUpdates(): Promise<AvailableUpdates> {
    const response = await fetch(`${window.sbwpData.restUrl}/safe-update/available`, {
        headers: { 'X-WP-Nonce': window.sbwpData.nonce }
    });
    if (!response.ok) throw new Error('Failed to fetch available updates');
    return response.json();
}

export async function createSafeUpdateSession(
    plugins: string[],
    themes: string[] = [],
    core: boolean = false
): Promise<{ success: boolean; session_id: number; clone_id: string }> {
    const response = await fetch(`${window.sbwpData.restUrl}/safe-update/session`, {
        method: 'POST',
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ plugins, themes, core })
    });
    if (!response.ok) throw new Error('Failed to create safe update session');
    return response.json();
}

export async function getSafeUpdateSession(sessionId: number): Promise<SafeUpdateSession> {
    const response = await fetch(`${window.sbwpData.restUrl}/safe-update/session/${sessionId}`, {
        headers: { 'X-WP-Nonce': window.sbwpData.nonce }
    });
    if (!response.ok) throw new Error('Failed to fetch session');
    return response.json();
}

export async function getRecentSessions(): Promise<Array<{
    id: number;
    clone_id: string;
    status: string;
    created_at: string;
}>> {
    const response = await fetch(`${window.sbwpData.restUrl}/safe-update/sessions`, {
        headers: { 'X-WP-Nonce': window.sbwpData.nonce }
    });
    if (!response.ok) throw new Error('Failed to fetch sessions');
    return response.json();
}

export async function applySafeUpdates(sessionId: number): Promise<{ success: boolean; message: string }> {
    const response = await fetch(`${window.sbwpData.restUrl}/safe-update/apply/${sessionId}`, {
        method: 'POST',
        headers: { 'X-WP-Nonce': window.sbwpData.nonce }
    });
    if (!response.ok) throw new Error('Failed to apply updates');
    return response.json();
}

// AI Settings types
export interface AISettings {
    is_configured: boolean;
    masked_key: string;
}

// Get AI settings
export async function getAISettings(): Promise<AISettings> {
    const response = await fetch(`${window.sbwpData.restUrl}/settings/ai`, {
        headers: { 'X-WP-Nonce': window.sbwpData.nonce }
    });
    if (!response.ok) throw new Error('Failed to fetch AI settings');
    return response.json();
}

// Save AI API key
export async function saveAISettings(apiKey: string): Promise<AISettings & { success: boolean }> {
    const response = await fetch(`${window.sbwpData.restUrl}/settings/ai`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': window.sbwpData.nonce
        },
        body: JSON.stringify({ api_key: apiKey })
    });
    if (!response.ok) throw new Error('Failed to save AI settings');
    return response.json();
}

// Get AI-humanized report summary
export async function humanizeReport(sessionId: number): Promise<{ summary: string }> {
    const response = await fetch(`${window.sbwpData.restUrl}/ai/humanize-report`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': window.sbwpData.nonce
        },
        body: JSON.stringify({ session_id: sessionId })
    });
    if (!response.ok) {
        const error = await response.json();
        throw new Error(error.message || 'Failed to get AI summary');
    }
    return response.json();
}

// Recovery Settings types
export interface RecoverySettings {
    url: string;
    key: string;
    has_pin: boolean;
}

export interface CrashStatus {
    has_crash: boolean;
    error_summary: string;
    recovery_url: string;
}

// Get recovery settings
export async function getRecoverySettings(): Promise<RecoverySettings> {
    const response = await fetch(`${window.sbwpData.restUrl}/recovery/settings`, {
        headers: { 'X-WP-Nonce': window.sbwpData.nonce }
    });
    if (!response.ok) throw new Error('Failed to fetch recovery settings');
    return response.json();
}

// Save recovery PIN
export async function saveRecoveryPin(pin: string): Promise<RecoverySettings> {
    const response = await fetch(`${window.sbwpData.restUrl}/recovery/settings`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': window.sbwpData.nonce
        },
        body: JSON.stringify({ pin })
    });
    if (!response.ok) throw new Error('Failed to save recovery PIN');
    return response.json();
}

// Regenerate recovery key
export async function regenerateRecoveryKey(): Promise<RecoverySettings> {
    const response = await fetch(`${window.sbwpData.restUrl}/recovery/regenerate-key`, {
        method: 'POST',
        headers: { 'X-WP-Nonce': window.sbwpData.nonce }
    });
    if (!response.ok) throw new Error('Failed to regenerate key');
    return response.json();
}

// Get crash status
export async function getCrashStatus(): Promise<CrashStatus> {
    const response = await fetch(`${window.sbwpData.restUrl}/crash-status`, {
        headers: { 'X-WP-Nonce': window.sbwpData.nonce }
    });
    if (!response.ok) throw new Error('Failed to get crash status');
    return response.json();
}
