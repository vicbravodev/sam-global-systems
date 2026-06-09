import { Head, router } from '@inertiajs/react';
import { ShieldOff } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Operator {
    id: number;
    name: string;
    email: string;
}

interface AdminOperatorsIndexProps {
    operators: Operator[];
}

export default function AdminOperatorsIndex({
    operators,
}: AdminOperatorsIndexProps) {
    const [email, setEmail] = useState('');

    const promote = () => {
        if (!email) {
            return;
        }

        router.post(
            '/admin/operators',
            { email },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Operador promovido.');
                    setEmail('');
                },
                onError: () =>
                    toast.error('No se pudo promover (¿email existente?).'),
            },
        );
    };

    const demote = (id: number) =>
        router.delete(`/admin/operators/${id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success('Operador degradado.'),
            onError: () => toast.error('No se pudo degradar.'),
        });

    return (
        <div className="flex h-full flex-col overflow-hidden">
            <Head title="Operadores" />

            <header className="flex shrink-0 items-center justify-between gap-3 border-b border-border bg-surface-1 px-5 py-3">
                <div className="flex items-center gap-3">
                    <h1 className="sam-h2 m-0">Operadores</h1>
                    <span className="sam-meta">
                        {operators.length} super-admins
                    </span>
                </div>
            </header>

            <div className="flex-1 overflow-y-auto p-5">
                <div className="mb-5 flex items-end gap-2 rounded-md border border-border bg-surface-1 p-4">
                    <div className="flex-1">
                        <Label htmlFor="operator-email" className="sam-meta">
                            Promover usuario existente a super-admin
                        </Label>
                        <Input
                            id="operator-email"
                            type="email"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            placeholder="user@empresa.com"
                        />
                    </div>
                    <Button onClick={promote} disabled={!email}>
                        Promover
                    </Button>
                </div>

                <div className="overflow-hidden rounded-md border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-surface-2 text-left">
                            <tr className="sam-meta">
                                <th className="px-3 py-2 font-medium">
                                    Operador
                                </th>
                                <th className="px-3 py-2 font-medium">Email</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {operators.map((operator) => (
                                <tr
                                    key={operator.id}
                                    className="border-t border-border"
                                >
                                    <td className="px-3 py-2 font-medium">
                                        {operator.name}
                                    </td>
                                    <td className="px-3 py-2">
                                        {operator.email}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => demote(operator.id)}
                                        >
                                            <ShieldOff size={13} /> Degradar
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
