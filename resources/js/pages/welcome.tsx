import { Head, usePage } from '@inertiajs/react';
import { AlertsSection } from '@/components/landing/alerts-section';
import { CamerasSection } from '@/components/landing/cameras-section';
import { CtaSection } from '@/components/landing/cta-section';
import { DecisionSection } from '@/components/landing/decision-section';
import { HeroSection } from '@/components/landing/hero-section';
import { IncludedSection } from '@/components/landing/included-section';
import { LandingFooter } from '@/components/landing/landing-footer';
import { LandingHeader } from '@/components/landing/landing-header';
import { NightSection } from '@/components/landing/night-section';
import { QuestionsSection } from '@/components/landing/questions-section';
import { TeamSection } from '@/components/landing/team-section';
import { dashboard, home } from '@/routes';

export default function Welcome() {
    const { auth, currentTeam } = usePage().props;
    const dashboardUrl = currentTeam ? dashboard(currentTeam.slug) : home();
    const authed = Boolean(auth.user);

    return (
        <>
            <Head title="SAM · Tu flota vigilada día y noche">
                <meta
                    name="description"
                    content="SAM vigila tus unidades día y noche, investiga cada alerta y te responde en español con los datos de tu operación. Solo te llama cuando de verdad importa."
                />
            </Head>

            <div className="theme-light min-h-dvh scroll-smooth bg-brand-paper text-brand-ink antialiased [color-scheme:light]">
                <LandingHeader authed={authed} dashboardUrl={dashboardUrl} />

                <main>
                    <HeroSection authed={authed} />
                    {/* Las demos de aquí abajo se cargan al acercarse al viewport. */}
                    <NightSection />
                    <TeamSection />
                    <CamerasSection />
                    <DecisionSection />
                    <QuestionsSection />
                    <AlertsSection />
                    <IncludedSection />
                    <CtaSection />
                </main>

                <LandingFooter />
            </div>
        </>
    );
}
