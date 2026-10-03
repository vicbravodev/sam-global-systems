import { moduleLabel } from '@/components/sam/settings/roles/lib';
import type { PermissionGroups } from '@/components/sam/settings/roles/lib';
import { Checkbox } from '@/components/ui/checkbox';

interface PermissionPickerProps {
    groups: PermissionGroups;
    selected: string[];
    onToggle: (code: string, checked: boolean) => void;
    disabled?: boolean;
}

export function PermissionPicker({
    groups,
    selected,
    onToggle,
    disabled,
}: PermissionPickerProps) {
    return (
        <div className="grid max-h-72 gap-3 overflow-y-auto rounded-md border border-border bg-surface-2 p-3">
            {Object.entries(groups).map(([module, options]) => (
                <div key={module}>
                    <div className="sam-caps mb-1.5">{moduleLabel(module)}</div>
                    <div className="grid gap-1.5">
                        {options.map((option) => (
                            <label
                                key={option.code}
                                title={option.code}
                                className="flex items-start gap-2 text-sm"
                            >
                                <Checkbox
                                    checked={selected.includes(option.code)}
                                    onCheckedChange={(checked) =>
                                        onToggle(option.code, checked === true)
                                    }
                                    disabled={disabled}
                                    className="mt-0.5"
                                />
                                <span className="min-w-0 leading-tight">
                                    {option.name}
                                </span>
                            </label>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}
