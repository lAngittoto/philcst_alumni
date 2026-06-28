@extends('layouts.public')
@section('content')
@include('layouts.header')

<style>
    html { scroll-behavior: smooth; }
    body { background-color: #FFFFFF; margin: 0; padding: 0; overflow-x: hidden; }

    [data-aos] {
        transition-timing-function: cubic-bezier(0.2, 0.8, 0.2, 1) !important;
    }

    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: #FFFFFF; }
    ::-webkit-scrollbar-thumb { background: #333333; border-radius: 10px; }

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

    /* Photo grid */
    .photo-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .photo-main { grid-column: 1 / -1; height: 340px; border-radius: 1.5rem; overflow: hidden; }
    .photo-side { height: 210px; border-radius: 1.5rem; overflow: hidden; }
    .photo-main img, .photo-side img {
        width: 100%; height: 100%;
        object-fit: cover; display: block;
    }

    @media (max-width: 768px) {
        .photo-grid { grid-template-columns: 1fr; }
        .photo-main { grid-column: 1; height: 240px; }
        .photo-side { height: 180px; }
    }
</style>

<div class="w-full mt-10">

    {{-- ══════════ MISSION & VISION ══════════ --}}
    <section class="pt-16 px-6 bg-white">
        <div class="max-w-5xl mx-auto">

            <div class="text-center mb-12" data-aos="fade-up" data-aos-duration="800">
                <h2 class="font-sans font-bold text-3xl md:text-4xl uppercase leading-tight text-[#333333]">
                    Mission &amp; Vision
                </h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">

                <div class="bg-[#FAFAFA] border-2 border-[#e0e0e0] rounded-2xl p-8" data-aos="fade-up" data-aos-duration="800" data-aos-delay="0">
                    <span class="font-sans font-bold text-sm uppercase tracking-[0.35em] text-[#7a3f91] block mb-4">Mission</span>
                    <p class="font-sans font-normal text-xl text-[#333333] leading-relaxed">
                        PhilCST provides quality education to students who are imbued with strong moral character through a well-balanced research and community oriented learning environment that develops critical thinking for maximum development of an individual's talents and capabilities.
                    </p>
                </div>

                <div class="bg-[#FAFAFA] border-2 border-[#e0e0e0] rounded-2xl p-8" data-aos="fade-up" data-aos-duration="800" data-aos-delay="100">
                    <span class="font-sans font-bold text-sm uppercase tracking-[0.35em] text-[#7a3f91] block mb-4">Vision</span>
                    <p class="font-sans font-normal text-xl text-[#333333] leading-relaxed">
                        PhilCST envisions to produce graduates fully equipped with knowledge, values, and skills, and who are globally competitive in their profession ever ready to render quality services.
                    </p>
                </div>

            </div>
        </div>
    </section>

    {{-- ══════════ SUBTLE DIVIDER ══════════ --}}
    <div class="px-6 bg-white">
        <div class="max-w-5xl mx-auto border-t-2 border-[#e0e0e0] mt-12"></div>
    </div>

    {{-- ══════════ HISTORY ══════════ --}}
    <section class="py-12 pb-24 px-6 bg-white">
        <div class="max-w-5xl mx-auto">

            <div class="mb-10" data-aos="fade-up" data-aos-duration="800">
                <span class="font-sans font-bold text-sm uppercase tracking-[0.35em] text-[#7a3f91] block mb-3">Our Heritage</span>
                <h2 class="font-sans font-bold text-3xl md:text-5xl uppercase leading-tight text-[#333333]">
                    History &amp;<br><span class="text-[#7a3f91]">Foundation</span>
                </h2>
            </div>

            <div class="bg-[#FAFAFA] border-l-4 border-[#7a3f91] rounded-r-xl p-8 mb-12"
                 data-aos="fade-up" data-aos-duration="800" data-aos-delay="100">
                <p class="font-sans font-normal text-xl text-[#333333] italic leading-relaxed m-0">
                    "The Philippine College of Science and Technology is a private, non-sectarian institution of higher learning — established as a beacon of hope after one of the most devastating disasters in Pangasinan's history."
                </p>
            </div>

            {{-- Timeline --}}
            <div class="mb-16">

                <div class="timeline-item" data-aos="fade-up" data-aos-duration="700" data-aos-delay="0">
                    <div class="timeline-connector">
                        <div class="timeline-dot"></div>
                        <div style="flex:1; width:2px; background:#e0e0e0; margin-top:8px;"></div>
                    </div>
                    <div class="pb-10">
                        <span class="font-sans font-bold text-sm uppercase tracking-[0.35em] text-[#7a3f91] block mb-2">July 16, 1990</span>
                        <h4 class="font-sans font-bold text-2xl uppercase text-[#333333] mb-3 mt-1">
                            The Earthquake That Changed Everything
                        </h4>
                        <p class="font-sans font-normal text-xl text-[#333333] leading-relaxed">
                            A powerful earthquake devastated Dagupan City where leading educational institutions and business establishments were heavily damaged. Massive destruction on public and private properties was described as similar to destructions encountered by thousands of people during historic wars.
                        </p>
                    </div>
                </div>

                <div class="timeline-item" data-aos="fade-up" data-aos-duration="700" data-aos-delay="100">
                    <div class="timeline-connector">
                        <div class="timeline-dot"></div>
                        <div style="flex:1; width:2px; background:#e0e0e0; margin-top:8px;"></div>
                    </div>
                    <div class="pb-10">
                        <span class="font-sans font-bold text-sm uppercase tracking-[0.35em] text-[#7a3f91] block mb-2">A Dream is Born</span>
                        <h4 class="font-sans font-bold text-2xl uppercase text-[#333333] mb-3 mt-1">
                            Mrs. Lourdes S. Fernandez — The Founder
                        </h4>
                        <p class="font-sans font-normal text-xl text-[#333333] leading-relaxed">
                            These disastrous circumstances convinced <strong>Mrs. Lourdes S. Fernandez</strong>, the PhilCST Founder, to put up a school outside Dagupan City to cater the needs of the students, parents, and professionals. This dream of the Founder was equally influenced by Fidel V. Ramos' marching program called <em>"Philippines 2000"</em> which aimed at transforming the country into a highly industrialized nation by the end of the century.
                        </p>
                    </div>
                </div>

                <div class="timeline-item" data-aos="fade-up" data-aos-duration="700" data-aos-delay="200">
                    <div class="timeline-connector">
                        <div class="timeline-dot"></div>
                        <div style="flex:1; width:2px; background:#e0e0e0; margin-top:8px;"></div>
                    </div>
                    <div class="pb-10">
                        <span class="font-sans font-bold text-sm uppercase tracking-[0.35em] text-[#7a3f91] block mb-2">1993</span>
                        <h4 class="font-sans font-bold text-2xl uppercase text-[#333333] mb-3 mt-1">
                            The Building Rises in Calasiao
                        </h4>
                        <p class="font-sans font-normal text-xl text-[#333333] leading-relaxed">
                            The Founder built a four-storey building in Nalsian, Calasiao, Pangasinan which was named the <strong>Philippine College of Science and Technology (PhilCST)</strong>. This was the first College Institution built in the Municipality of Calasiao, which is just five (5) kilometers away from Dagupan City proper.
                        </p>
                    </div>
                </div>

                <div class="timeline-item" data-aos="fade-up" data-aos-duration="700" data-aos-delay="300">
                    <div class="timeline-connector">
                        <div class="timeline-dot"></div>
                    </div>
                    <div>
                        <span class="font-sans font-bold text-sm uppercase tracking-[0.35em] text-[#7a3f91] block mb-2">June 4, 1995</span>
                        <h4 class="font-sans font-bold text-2xl uppercase text-[#333333] mb-3 mt-1">
                            Doors Open to the Public
                        </h4>
                        <p class="font-sans font-normal text-xl text-[#333333] leading-relaxed">
                            PhilCST formally started its overall operations, offering to the public various degree and non-degree courses anchored on the mission of recognizing every human being as a potential agent of positive change. The primary aim of PhilCST was to provide high-quality education to children of the residents in the province of Pangasinan and the neighboring towns, cities, and provinces.
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
                <div class="photo-side bg-[#FAFAFA] flex items-center justify-center">
                    <div class="text-center">
                        <p class="font-sans font-bold text-5xl text-[#7a3f91] m-0">1995</p>
                        <span class="font-sans font-bold text-sm uppercase tracking-[0.35em] text-[#333333] block mt-3">Year Established</span>
                    </div>
                </div>
            </div>

        </div>
    </section>

    {{-- ══════════ EXCELLENCE ══════════ --}}
    <section class="py-24 px-6 bg-[#FAFAFA]">
        <div class="max-w-5xl mx-auto">

            <div class="mb-10" data-aos="fade-up" data-aos-duration="800">
                <span class="font-sans font-bold text-sm uppercase tracking-[0.35em] text-[#7a3f91] block mb-3">Continuing Legacy</span>
                <h2 class="font-sans font-bold text-3xl md:text-5xl uppercase leading-tight text-[#333333]">
                    A Tradition of <br><span class="text-[#7a3f91]">Excellence Continues</span>
                </h2>
            </div>

            <p class="font-sans font-normal text-xl text-[#333333] leading-relaxed"
               data-aos="fade-up" data-aos-duration="800" data-aos-delay="100">
                The Philippine College of Science and Technology (PhilCST) is a premier higher education institution in the North. Renowned for its unwavering commitment to high-quality education and comprehensive student development, PhilCST stands out as a leader in the region. With innovative academic programs, groundbreaking research initiatives, and robust partnerships with industry, it empowers students to thrive in their chosen careers. By focusing on science and technology, PhilCST is dedicated to shaping skilled professionals who are well-equipped to drive progress and contribute meaningfully to national development and beyond.
            </p>

        </div>
    </section>

</div>

@include('layouts.footer')

@endsection