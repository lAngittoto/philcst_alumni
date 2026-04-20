<style>
    /* ─── FOOTER FONT SYSTEM ─── */
    /* Brand title  → Courier New, 900, uppercase, #c59dd9            */
    /* Section label→ Courier New, 700, 0.8rem, letter-spacing 0.35em */
    /* Body / desc  → Georgia, serif, 1.05rem, white/70               */
    /* Contact info → Georgia, serif, 1rem, white/80                  */
    /* Copyright    → Courier New, 700, 0.7rem, white/40              */

    .ftr-brand {
        font-family: 'Courier New', monospace;
        font-weight: 900;
        font-size: clamp(1.3rem, 3vw, 1.75rem);    /* 21–28px */
        text-transform: uppercase;
        letter-spacing: -0.01em;
        line-height: 1.15;
        color: #c59dd9;
    }
    .ftr-label {
        font-family: 'Courier New', monospace;
        font-weight: 700;
        font-size: 0.8rem;                          /* 12.8px — same as About labels */
        text-transform: uppercase;
        letter-spacing: 0.35em;
        color: #c59dd9;
        display: block;
    }
    .ftr-body {
        font-family: 'Georgia', serif;
        font-size: 1.05rem;                         /* 16.8px */
        line-height: 1.85;
        color: rgba(255,255,255,0.70);
        font-style: italic;
    }
    .ftr-contact {
        font-family: 'Georgia', serif;
        font-size: 1rem;                            /* 16px */
        line-height: 1.75;
        color: rgba(255,255,255,0.80);
    }
    .ftr-social-name {
        font-family: 'Courier New', monospace;
        font-weight: 700;
        font-size: 0.8rem;                          /* 12.8px */
        text-transform: uppercase;
        letter-spacing: 0.12em;
        line-height: 1.4;
        color: #ffffff;
    }
    .ftr-copyright {
        font-family: 'Courier New', monospace;
        font-weight: 700;
        font-size: 0.7rem;                          /* 11.2px */
        text-transform: uppercase;
        letter-spacing: 0.35em;
        color: rgba(255,255,255,0.40);
    }
</style>

<footer class="bg-[#2b0d3e] text-white pt-16 md:pt-24 pb-12 px-6">
    <div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-12 md:gap-16
                border-b border-white/10 pb-12 md:pb-16 mb-12 text-center md:text-left">

        {{-- ── Brand Column ── --}}
        <div class="space-y-4 md:space-y-6">
            <p class="ftr-brand">PHILCST<br>Alumni Connect</p>
            <p class="ftr-body" style="max-width:22rem; margin:0 auto 0 0;">
                Empowering alumni through technology.
            </p>
        </div>

        {{-- ── Location Column ── --}}
        <div class="space-y-5">
            <span class="ftr-label" style="margin-bottom:1rem;">Our Location</span>

            <p class="ftr-contact flex flex-col md:flex-row items-center md:items-start
                      justify-center md:justify-start gap-3">
                <i class="fa-solid fa-location-dot text-[#c59dd9] text-xl mt-0.5"></i>
                <span>Old Nalsian Road, Nalsian,<br>Calasiao, Philippines, 2418</span>
            </p>

            <p class="ftr-contact flex items-center justify-center md:justify-start gap-4">
                <i class="fa-solid fa-phone text-[#c59dd9] text-base"></i>
                (075) 522 8032
            </p>

            <p class="ftr-contact flex items-center justify-center md:justify-start gap-4">
                <i class="fa-solid fa-envelope text-[#c59dd9] text-base"></i>
                philcstreg@yahoo.com
            </p>
        </div>

        {{-- ── Social Column ── --}}
        <div class="space-y-5">
            <span class="ftr-label" style="margin-bottom:1rem;">Social Connect</span>

            <a href="https://www.facebook.com" target="_blank"
               class="flex items-center justify-center md:justify-start gap-4
                      bg-white/5 hover:bg-white/10 p-4 rounded-2xl
                      transition-all duration-300 group">
                <i class="fa-brands fa-facebook text-[#c59dd9] text-4xl
                          group-hover:scale-110 transition-transform duration-300"></i>
                <span class="ftr-social-name text-left">
                    Philippine College of<br>Science and Technology
                </span>
            </a>
        </div>

    </div>

    {{-- ── Copyright ── --}}
    <div class="text-center">
        <p class="ftr-copyright">© 2026 PHILCST. All Rights Reserved.</p>
    </div>
</footer>