export type RoleValue = 'admin' | 'editor' | 'viewer';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    role: RoleValue | null;
    role_label: string | null;
    two_factor_enabled: boolean;
    must_enable_two_factor: boolean;
};

export type Auth = {
    user: User;
    permissions: string[];
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};

export type RoleOption = {
    value: RoleValue;
    label: string;
    description: string;
};
