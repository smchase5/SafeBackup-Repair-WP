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
    size_bytes: number;
    status: string;
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

export async function createBackup(): Promise<any> {
    const response = await fetch(`${window.sbwpData.restUrl}/backups`, {
        method: 'POST',
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce,
            'Content-Type': 'application/json'
        }
    });
    if (!response.ok) throw new Error('Failed to create backup');
    return response.json();
}

export interface CloudProvider {
    id: string;
    name: string;
    icon: string;
    connected: boolean;
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

export async function connectProvider(providerId: string, action: 'connect' | 'disconnect'): Promise<any> {
    const response = await fetch(`${window.sbwpData.restUrl}/cloud/connect`, {
        method: 'POST',
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ provider_id: providerId, action })
    });
    if (!response.ok) throw new Error('Failed to update provider');
    return response.json();
}

export interface Settings {
    retention_limit: number;
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
