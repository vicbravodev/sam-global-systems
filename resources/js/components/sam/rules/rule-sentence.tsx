import { ArrowRight } from 'lucide-react';
import { Fragment } from 'react';
import type { ConditionFieldDef } from '@/components/sam/condition-builder';
import { StatusBadge } from '@/components/sam/status-badge';
import { decisionOutcomeEffectLabel } from '@/lib/labels';
import { cn } from '@/lib/utils';
import { conditionsToSentence, OUTCOME_TONE, outcomeGroup } from './lib';
import type { SentenceNode } from './lib';

/** Resultado de una regla como píldora con color semántico + texto. */
export function OutcomePill({
    code,
    className,
}: {
    code: string | null;
    className?: string;
}) {
    return (
        <StatusBadge
            size="sm"
            dot
            tone={OUTCOME_TONE[outcomeGroup(code)]}
            label={decisionOutcomeEffectLabel(code)}
            className={cn('gap-1.5 text-xs font-medium', className)}
        />
    );
}

function Leaf({ node }: { node: Extract<SentenceNode, { kind: 'leaf' }> }) {
    return (
        <span className="rounded-sm bg-surface-2 px-1.5 py-0.5 text-fg-2">
            {node.subject}
            {node.predicate && (
                <>
                    {' '}
                    <span className="font-medium text-fg-1">
                        {node.predicate}
                    </span>
                </>
            )}
        </span>
    );
}

function Node({ node, nested }: { node: SentenceNode; nested: boolean }) {
    if (node.kind === 'leaf') {
        return <Leaf node={node} />;
    }

    const connector = node.logic === 'all' ? 'y' : 'o';

    return (
        <>
            {nested && <span className="text-fg-3">(</span>}
            {node.children.map((child, index) => (
                <Fragment key={index}>
                    {index > 0 && (
                        <span className="text-fg-3">{connector}</span>
                    )}
                    <Node node={child} nested />
                </Fragment>
            ))}
            {nested && <span className="text-fg-3">)</span>}
        </>
    );
}

/**
 * La regla como frase: "Si <condiciones en español> → <resultado>". Las
 * condiciones se traducen con el catálogo de campos del backend (etiqueta
 * del campo y de cada opción), nunca con la clave técnica.
 */
export function RuleSentence({
    conditions,
    fields,
    outcomeCode,
    className,
}: {
    conditions: Record<string, unknown> | null;
    fields: ConditionFieldDef[];
    outcomeCode?: string | null;
    className?: string;
}) {
    const sentence = conditionsToSentence(conditions, fields);

    return (
        <p
            className={cn(
                'flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs leading-relaxed',
                className,
            )}
        >
            <span className="font-semibold text-fg-3">Si</span>
            {sentence === null ? (
                <span className="rounded-sm bg-surface-2 px-1.5 py-0.5 text-fg-2">
                    llega cualquier evento
                </span>
            ) : (
                <Node node={sentence} nested={false} />
            )}
            {outcomeCode !== undefined && (
                <>
                    <ArrowRight
                        className="size-3.5 shrink-0 text-fg-3"
                        aria-label="entonces"
                    />
                    <OutcomePill code={outcomeCode} />
                </>
            )}
        </p>
    );
}
