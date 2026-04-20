@extends('layouts.public')
@section('content')
@include('layouts.header')

<style>
    html { scroll-behavior: smooth; -webkit-font-smoothing: antialiased; }
    body { margin: 0; padding: 0; background-color: #f9f7fc; }

    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: #f9f7fc; }
    ::-webkit-scrollbar-thumb { background: #2b0d3e; border-radius: 10px; }

    [data-aos] {
        transition-timing-function: cubic-bezier(0.2, 0.8, 0.2, 1) !important;
    }

    /* ─── FONT SIZES (aligned with About page) ─── */
    /* Label        → 0.8rem  / 12.8px  */
    /* Hero body    → 1.15rem / 18.4px  */
    /* Card body    → 1.05rem / 16.8px  */
    /* Card title   → 1.15rem / 18.4px  */
    /* Hero h2      → clamp(2.5rem, 6vw, 4.5rem) */

    .home-label {
        font-family: 'Courier New', monospace;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.35em;
        text-transform: uppercase;
        color: #7a3f91;
        display: block;
    }
    .home-title {
        font-family: 'Courier New', monospace;
        font-weight: 900;
        text-transform: uppercase;
        color: #2b0d3e;
        letter-spacing: -0.02em;
        line-height: 1.08;
    }
    .home-body {
        font-family: 'Georgia', serif;
        font-size: 1.15rem;
        line-height: 1.95;
        color: #4a4056;
    }

    /* ─── FEATURE CARDS ─── */
    .feature-card {
        background: #ffffff;
        border-radius: 2rem;
        border: 1.5px solid #e8e0f0;
        padding: 2.75rem 2rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        transition: box-shadow 0.3s ease, transform 0.3s ease,
                    border-color 0.3s, background 0.3s;
    }
    .feature-card:hover {
        background: #f3ecfa;
        box-shadow: 0 14px 45px rgba(122,63,145,0.14);
        transform: translateY(-6px);
        border-color: #b87fd4;
    }

    /* ─── ICON — no circle, just big FA icon ─── */
    .feature-icon {
        font-size: 3.5rem;          /* 56px */
        color: #7a3f91;
        margin-bottom: 1.75rem;
        transition: color 0.3s, transform 0.3s;
        line-height: 1;
    }
    .feature-card:hover .feature-icon {
        color: #9b51b8;
        transform: scale(1.12);
    }

    /* ─── HERO ACCENT LINE ─── */
    .hero-accent-line {
        width: 3rem;
        height: 2px;
        background: #7a3f91;
        margin: 0.75rem auto 0;
    }

    /* ─── SECTION DIVIDER ─── */
    .section-divider {
        padding: 0 1.5rem;
        background: #f9f7fc;
    }
    .section-divider-inner {
        max-width: 64rem;
        margin: 0 auto;
        border-top: 1.5px solid #e0d5ee;
    }
</style>

<main class="w-full overflow-x-hidden" style="background:#f9f7fc;">

    {{-- ══ HERO IMAGE ══ --}}
    <section class="relative w-full flex flex-col items-center" style="background:#f9f7fc;">
        <div class="w-full h-[50vh] md:h-[80vh] overflow-hidden">
            <img src="{{ asset('images/philcst-img.jpg') }}"
                 alt="PhilCST Background"
                 class="w-full h-full object-cover md:object-contain"
                 data-aos="fade-in" data-aos-duration="1500">
        </div>
    </section>

    {{-- ══ HERO TEXT ══ --}}
    <section class="relative z-10 py-16 md:py-24" style="background:#f9f7fc;">
        <div class="max-w-5xl mx-auto px-6 text-center">

            <div class="inline-block mb-10" data-aos="fade-up" data-aos-delay="0">
                <span class="home-label">Official Alumni Platform</span>
                <div class="hero-accent-line"></div>
            </div>

            <h2 class="home-title mb-8"
                style="font-size: clamp(2.5rem, 6vw, 4.5rem);"
                data-aos="fade-up" data-aos-delay="200">
                Connecting Alumni.<br>
                <span style="color:#7a3f91;">Empowering Futures.</span>
            </h2>

            <p class="home-body mx-auto"
               style="max-width:44rem; font-size:1.15rem;"
               data-aos="fade-up" data-aos-delay="400">
                The Philippine College of Science and Technology's digital home for alumni.
                Reconnect with batchmates, explore career opportunities, and stay connected with your alma mater.
            </p>

        </div>
    </section>

    {{-- ══ DIVIDER ══ --}}
    <div class="section-divider">
        <div class="section-divider-inner"></div>
    </div>

    {{-- ══ FEATURE CARDS ══ --}}
    <section class="py-16 pb-32 px-6 w-full" style="background:#f9f7fc;">
        <div class="max-w-5xl mx-auto">

            <div class="text-center mb-12" data-aos="fade-up" data-aos-duration="800">
                <span class="home-label" style="margin-bottom:0.75rem;">What We Offer</span>
                <h2 class="home-title mt-2"
                    style="font-size: clamp(2rem, 4vw, 3rem);">
                    Everything You Need,<br>
                    <span style="color:#7a3f91;">In One Place</span>
                </h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">

                <div class="feature-card" data-aos="fade-up" data-aos-delay="100">
                    <i class="fa-solid fa-id-badge feature-icon"></i>
                    <h3 class="home-title mb-4" style="font-size:1.15rem;">Alumni Profiles</h3>
                    <p class="home-body" style="font-size:1.05rem;">
                        Update your professional and academic journey with our secure alumni profiles.
                    </p>
                </div>

                <div class="feature-card" data-aos="fade-up" data-aos-delay="200">
                    <i class="fa-solid fa-calendar-check feature-icon"></i>
                    <h3 class="home-title mb-4" style="font-size:1.15rem;">Events &amp; Reunions</h3>
                    <p class="home-body" style="font-size:1.05rem;">
                        Stay updated on campus events, reunions, and alumni activities.
                    </p>
                </div>

                <div class="feature-card" data-aos="fade-up" data-aos-delay="300">
                    <i class="fa-solid fa-briefcase feature-icon"></i>
                    <h3 class="home-title mb-4" style="font-size:1.15rem;">Job Opportunities</h3>
                    <p class="home-body" style="font-size:1.05rem;">
                        Explore available job opportunities shared through the system.
                    </p>
                </div>

            </div>
        </div>
    </section>

</main>

@include('layouts.footer')

@endsection