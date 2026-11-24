export type SegmentFieldDef = {
    key: string;
    label: string;
    group: string;
    ops: string[];
    input: string;
    options?: { value: string; label: string }[];
};

export type SegmentCondition = {
    field: string;
    op: string;
    value: string | number | null;
};

export type CampaignOption = {
    id: number;
    name: string;
    historic: boolean;
    date: string | null;
};
