@extends('layouts.public')
@section('content')
@include('layouts.header')

<style>
    html { scroll-behavior: smooth; }
    body { background-color: #f9f7fc; margin: 0; padding: 0; overflow-x: hidden; }

    [data-aos="bounce-up"] {
        transform: translateY(60px); opacity: 0;
        transition-property: transform, opacity;
    }
    [data-aos="bounce-up"].aos-animate {
        transform: translateY(0); opacity: 1;
        transition-timing-function: cubic-bezier(0.175, 0.885, 0.32, 1.275) !important;
    }

    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: #f9f7fc; }
    ::-webkit-scrollbar-thumb { background: #2b0d3e; border-radius: 10px; }

    /* ─── FONT SIZES (all explicit) ─── */
    /* Label        → 0.8rem  / 12.8px  */
    /* Body text    → 1.15rem / 18.4px  */
    /* Card body    → 1.1rem  / 17.6px  */
    /* Timeline h4  → 1.3rem  / 20.8px  */
    /* Pillar h4    → 1.05rem / 16.8px  */
    /* Pull quote   → 1.2rem  / 19.2px  */
    /* Section h2   → clamp(2.2rem, 5vw, 3.2rem) */
    /* History h2   → clamp(2.8rem, 6vw, 4.5rem) */
    /* Excellence h2→ clamp(2rem, 4vw, 3rem)     */

    .about-label {
        font-family: 'Courier New', monospace;
        font-size: 0.8rem;          /* 12.8px */
        font-weight: 700;
        letter-spacing: 0.35em;
        text-transform: uppercase;
        color: #7a3f91;
        display: block;
    }
    .section-title {
        font-family: 'Courier New', monospace;
        font-weight: 900;
        text-transform: uppercase;
        color: #2b0d3e;
        letter-spacing: -0.02em;
        line-height: 1.08;
    }
    .body-text {
        font-family: 'Georgia', serif;
        font-size: 1.15rem;         /* 18.4px */
        line-height: 1.95;
        color: #4a4056;
    }

    /* Mission/Vision cards */
    .mv-card {
        background: #ffffff;
        border-radius: 2rem;
        border: 1.5px solid #e8e0f0;
        padding: 2.5rem;
        position: relative;
        overflow: hidden;
        transition: box-shadow 0.3s ease, transform 0.3s ease;
    }
    .mv-card:hover {
        box-shadow: 0 12px 40px rgba(43,13,62,0.1);
        transform: translateY(-4px);
    }
    .mv-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0; height: 4px;
        background: linear-gradient(90deg, #2b0d3e, #7a3f91);
    }

    /* Timeline */
    .timeline-item { display: flex; gap: 1.5rem; align-items: flex-start; }
    .timeline-dot {
        flex-shrink: 0;
        width: 13px; height: 13px;
        border-radius: 50%;
        background: #7a3f91;
        margin-top: 0.5rem;
        box-shadow: 0 0 0 4px rgba(122,63,145,0.18);
    }
    .timeline-connector {
        flex-shrink: 0;
        display: flex; flex-direction: column;
        align-items: center; width: 13px;
    }

    /* Pillar cards */
    .pillar-card {
        background: #ffffff;
        border-radius: 1.5rem;
        border: 1.5px solid #e8e0f0;
        padding: 2rem;
        transition: border-color 0.3s, box-shadow 0.3s, transform 0.3s;
    }
    .pillar-card:hover {
        border-color: #7a3f91;
        box-shadow: 0 8px 30px rgba(122,63,145,0.12);
        transform: translateY(-3px);
    }

    /* Photo grid */
    .photo-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .photo-main { grid-column: 1 / -1; height: 340px; border-radius: 1.5rem; overflow: hidden; }
    .photo-side { height: 210px; border-radius: 1.5rem; overflow: hidden; }
    .photo-main img, .photo-side img {
        width: 100%; height: 100%;
        object-fit: cover; display: block;
        transition: transform 1s ease;
    }
    .photo-main:hover img, .photo-side:hover img { transform: scale(1.05); }

    /* Pull quote */
    .pull-quote {
        background: #f3ecfa;
        border-left: 5px solid #7a3f91;
        border-radius: 0 1rem 1rem 0;
        padding: 2rem 2.5rem;
    }

    @media (max-width: 768px) {
        .photo-grid { grid-template-columns: 1fr; }
        .photo-main { grid-column: 1; height: 240px; }
        .photo-side { height: 180px; }
        .mv-card { padding: 2rem 1.5rem; }
    }
</style>

<div class="w-full mt-10">

    {{-- ══════════ MISSION & VISION ══════════ --}}
    <section class="pt-16 px-6" style="background:#f9f7fc;">
        <div class="max-w-5xl mx-auto">

            <div class="text-center" style="margin-bottom:3rem;" data-aos="fade-up" data-aos-duration="800">
                <h2 class="section-title" style="font-size: clamp(2.2rem, 5vw, 3.2rem);">
                    Mission &amp; Vision
                </h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">

                <div class="mv-card" data-aos="bounce-up" data-aos-duration="1000" data-aos-delay="0">
                    <span class="about-label" style="margin-bottom:1.25rem;">Mission</span>
                    <p class="body-text" style="font-size:1.1rem;">
                        PhilCST provides quality education to students who are imbued with strong moral character through a well-balanced research and community-oriented learning environment that develops critical thinking for the maximum development of an individual's talents and capabilities.
                    </p>
                </div>

                <div class="mv-card" data-aos="bounce-up" data-aos-duration="1000" data-aos-delay="150">
                    <span class="about-label" style="margin-bottom:1.25rem;">Vision</span>
                    <p class="body-text" style="font-size:1.1rem;">
                        PhilCST envisions producing graduates fully equipped with knowledge, values, and skills, who are globally competitive in their profession and ever ready to render quality services to society.
                    </p>
                </div>

            </div>
        </div>
    </section>

    {{-- ══════════ SUBTLE DIVIDER ══════════ --}}
    <div style="background:#f9f7fc; padding:0 1.5rem;">
        <div class="max-w-5xl mx-auto" style="border-top:1.5px solid #e0d5ee; margin-top:3.5rem;"></div>
    </div>

    {{-- ══════════ HISTORY ══════════ --}}
    <section class="pt-12 pb-24 px-6" style="background:#f9f7fc;">
        <div class="max-w-5xl mx-auto">

            <div style="margin-bottom:2.5rem;" data-aos="fade-up" data-aos-duration="800">
                <span class="about-label" style="margin-bottom:0.75rem;">Our Heritage</span>
                <h2 class="section-title" style="font-size: clamp(2.8rem, 6vw, 4.5rem); margin-top:0.5rem;">
                    History &amp;<br><span style="color:#7a3f91;">Foundation</span>
                </h2>
            </div>

            <div class="pull-quote" style="margin-bottom:3rem;"
                 data-aos="fade-up" data-aos-duration="800" data-aos-delay="100">
                <p class="body-text"
                   style="color:#2b0d3e; font-style:italic; font-size:1.2rem; font-weight:bold; margin:0;">
                    "The Philippine College of Science and Technology is a private, non-sectarian institution of higher learning — established as a beacon of hope after one of the most devastating disasters in Pangasinan's history."
                </p>
            </div>

            {{-- Timeline --}}
            <div style="margin-bottom:4rem;">

                <div class="timeline-item" data-aos="fade-up" data-aos-duration="700" data-aos-delay="0">
                    <div class="timeline-connector">
                        <div class="timeline-dot"></div>
                        <div style="flex:1; width:2px; background:#e0d5ee; margin-top:8px;"></div>
                    </div>
                    <div style="padding-bottom:2.5rem;">
                        <span class="about-label" style="margin-bottom:0.5rem;">July 16, 1990</span>
                        <h4 class="section-title" style="font-size:1.3rem; margin-bottom:0.75rem; margin-top:0.4rem;">
                            The Earthquake That Changed Everything
                        </h4>
                        <p class="body-text">
                            A powerful earthquake devastated Dagupan City, heavily damaging leading educational institutions and business establishments. The massive destruction on public and private properties was likened to the devastations of historic wars — leaving thousands of families in desperate need of a fresh start.
                        </p>
                    </div>
                </div>

                <div class="timeline-item" data-aos="fade-up" data-aos-duration="700" data-aos-delay="100">
                    <div class="timeline-connector">
                        <div class="timeline-dot"></div>
                        <div style="flex:1; width:2px; background:#e0d5ee; margin-top:8px;"></div>
                    </div>
                    <div style="padding-bottom:2.5rem;">
                        <span class="about-label" style="margin-bottom:0.5rem;">A Dream is Born</span>
                        <h4 class="section-title" style="font-size:1.3rem; margin-bottom:0.75rem; margin-top:0.4rem;">
                            Mrs. Lourdes S. Fernandez — The Founder
                        </h4>
                        <p class="body-text">
                            These disastrous circumstances convinced <strong>Mrs. Lourdes S. Fernandez</strong> to establish a school outside Dagupan City to serve students, parents, and professionals. Her vision was equally inspired by President Fidel V. Ramos' <em>"Philippines 2000"</em> program — which aimed at transforming the country into a highly industrialized nation by the turn of the century.
                        </p>
                    </div>
                </div>

                <div class="timeline-item" data-aos="fade-up" data-aos-duration="700" data-aos-delay="200">
                    <div class="timeline-connector">
                        <div class="timeline-dot"></div>
                        <div style="flex:1; width:2px; background:#e0d5ee; margin-top:8px;"></div>
                    </div>
                    <div style="padding-bottom:2.5rem;">
                        <span class="about-label" style="margin-bottom:0.5rem;">1993</span>
                        <h4 class="section-title" style="font-size:1.3rem; margin-bottom:0.75rem; margin-top:0.4rem;">
                            The Building Rises in Calasiao
                        </h4>
                        <p class="body-text">
                            The Founder constructed a four-storey building in Nalsian, Calasiao, Pangasinan — officially named the <strong>Philippine College of Science and Technology (PhilCST)</strong>. This became the first college institution in the Municipality of Calasiao, just five kilometers from Dagupan City proper.
                        </p>
                    </div>
                </div>

                <div class="timeline-item" data-aos="fade-up" data-aos-duration="700" data-aos-delay="300">
                    <div class="timeline-connector">
                        <div class="timeline-dot"></div>
                    </div>
                    <div>
                        <span class="about-label" style="margin-bottom:0.5rem;">June 4, 1995</span>
                        <h4 class="section-title" style="font-size:1.3rem; margin-bottom:0.75rem; margin-top:0.4rem;">
                            Doors Open to the Public
                        </h4>
                        <p class="body-text">
                            PhilCST formally started its overall operations, offering various degree and non-degree courses anchored on the mission of recognizing every human being as a potential agent of positive change. The primary aim was to provide high-quality education to children of residents across the province of Pangasinan and neighboring communities.
                        </p>
                    </div>
                </div>

            </div>

            {{-- Photos below all text --}}
            <div class="photo-grid" data-aos="fade-up" data-aos-duration="1000">
                <div class="photo-main">
                    <img src="{{ asset('images/school.jpg') }}" alt="PhilCST Main Campus">
                </div>
                <div class="photo-side">
                    <img src="{{ asset('images/school-1.jpg') }}" alt="PhilCST Campus">
                </div>
                <div class="photo-side"
                     style="background:#ede4f5; display:flex; align-items:center; justify-content:center;">
                    <div style="text-align:center; padding:1rem;">
                        <p class="section-title" style="font-size:3.5rem; color:#7a3f91; margin:0;">1995</p>
                        <span class="about-label" style="margin-top:0.6rem; color:#2b0d3e;">Year Established</span>
                    </div>
                </div>
            </div>

        </div>
    </section>

    {{-- ══════════ EXCELLENCE ══════════ --}}
    <section class="py-24 px-6" style="background:#ffffff;">
        <div class="max-w-5xl mx-auto">

            <div class="text-center" style="margin-bottom:3.5rem;" data-aos="fade-up" data-aos-duration="800">
                <span class="about-label" style="margin-bottom:0.75rem;">Continuing Legacy</span>
                <h2 class="section-title"
                    style="font-size: clamp(2rem, 4vw, 3rem); margin-bottom:1.25rem; margin-top:0.5rem;">
                    A Tradition of <span style="color:#7a3f91;">Excellence Continues</span>
                </h2>
                <p class="body-text" style="max-width:40rem; margin:0 auto; font-size:1.1rem;">
                    PhilCST stands out as a regional leader — renowned for high-quality education, innovation, and producing graduates ready for the world.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                <div class="pillar-card" data-aos="bounce-up" data-aos-duration="900" data-aos-delay="0">
                    <div style="width:2.75rem; height:2.75rem; border-radius:50%; background:#f3ecfa;
                                display:flex; align-items:center; justify-content:center; margin-bottom:1.25rem;">
                        <span class="section-title" style="color:#7a3f91; font-size:0.85rem;">01</span>
                    </div>
                    <h4 class="section-title" style="font-size:1.05rem; margin-bottom:0.75rem;">Premier Institution</h4>
                    <p class="body-text" style="font-size:1.05rem;">
                        A leading higher education institution in the North, known for its unwavering commitment to quality education and comprehensive student development.
                    </p>
                </div>

                <div class="pillar-card" data-aos="bounce-up" data-aos-duration="900" data-aos-delay="120">
                    <div style="width:2.75rem; height:2.75rem; border-radius:50%; background:#f3ecfa;
                                display:flex; align-items:center; justify-content:center; margin-bottom:1.25rem;">
                        <span class="section-title" style="color:#7a3f91; font-size:0.85rem;">02</span>
                    </div>
                    <h4 class="section-title" style="font-size:1.05rem; margin-bottom:0.75rem;">Innovation &amp; Research</h4>
                    <p class="body-text" style="font-size:1.05rem;">
                        Innovative academic programs, groundbreaking research, and strong industry partnerships help PhilCST graduates thrive in today's competitive landscape.
                    </p>
                </div>

                <div class="pillar-card" data-aos="bounce-up" data-aos-duration="900" data-aos-delay="240">
                    <div style="width:2.75rem; height:2.75rem; border-radius:50%; background:#f3ecfa;
                                display:flex; align-items:center; justify-content:center; margin-bottom:1.25rem;">
                        <span class="section-title" style="color:#7a3f91; font-size:0.85rem;">03</span>
                    </div>
                    <h4 class="section-title" style="font-size:1.05rem; margin-bottom:0.75rem;">Global Competitiveness</h4>
                    <p class="body-text" style="font-size:1.05rem;">
                        Focused on science and technology, PhilCST molds skilled professionals equipped to drive national progress and contribute meaningfully on the global stage.
                    </p>
                </div>

            </div>
        </div>
    </section>

</div>

@include('layouts.footer')

@endsection