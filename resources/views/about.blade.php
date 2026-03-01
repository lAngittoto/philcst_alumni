@extends('layouts.public')
@section('content')
@include('layouts.header')

<style>
    html { scroll-behavior: smooth; }
    body { background-color: #ffffff; margin: 0; padding: 0; overflow-x: hidden; }

    /* Ito ang sikreto para sa Bounce ng AOS */
    [data-aos="bounce-up"] {
        transform: translateY(100px);
        opacity: 0;
        transition-property: transform, opacity;
    }

    [data-aos="bounce-up"].aos-animate {
        transform: translateY(0);
        opacity: 1;
        /* Cubic Bezier na may 'overshoot' para sa bounce effect */
        transition-timing-function: cubic-bezier(0.175, 0.885, 0.32, 1.275) !important;
    }

    /* Minimalist Scrollbar */
    ::-webkit-scrollbar { width: 8px; }
    ::-webkit-scrollbar-track { background: white; }
    ::-webkit-scrollbar-thumb { background: #2b0d3e; border-radius: 10px; }
</style>

<main class="w-full bg-white font-mono antialiased text-[#2b0d3e] mt-10">

    <section class="relative min-h-[70vh] flex items-center justify-center py-24 px-6">
        <div class="max-w-6xl mx-auto w-full">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-16 md:gap-24 text-center md:text-left">
                
                <div class="group" data-aos="bounce-up" data-aos-duration="1200">
                    <div class="mb-8">
                        <span class="text-[#7a3f91] text-xs font-bold uppercase tracking-[0.5em] mb-3 block text-center md:text-left">Our Purpose</span>
                        <h2 class="text-4xl md:text-6xl font-black text-[#2b0d3e] tracking-tighter uppercase">Mission</h2>
                        <div class="h-1.5 w-16 bg-[#7a3f91] mt-3 rounded-full transition-all duration-500 group-hover:w-32 mx-auto md:mx-0"></div>
                    </div>
                    <p class="text-xl md:text-2xl text-gray-500 leading-relaxed font-medium italic">
                        "PhilCST provides quality education to students who are imbued with strong moral character through a well-balanced research and community-oriented learning environment."
                    </p>
                </div>

                <div class="group" data-aos="bounce-up" data-aos-duration="1200" data-aos-delay="300">
                    <div class="mb-8">
                        <span class="text-[#7a3f91] text-xs font-bold uppercase tracking-[0.5em] mb-3 block text-center md:text-left">Our Goal</span>
                        <h2 class="text-4xl md:text-6xl font-black text-[#2b0d3e] tracking-tighter uppercase">Vision</h2>
                        <div class="h-1.5 w-16 bg-[#7a3f91] mt-3 rounded-full transition-all duration-500 group-hover:w-32 mx-auto md:mx-0"></div>
                    </div>
                    <p class="text-xl md:text-2xl text-gray-500 leading-relaxed font-medium italic">
                        "PhilCST envisions producing graduates fully equipped with knowledge, values, and skills who are globally competitive and ever ready to render quality services."
                    </p>
                </div>

            </div>
        </div>
    </section>

    <section class="relative py-24 md:py-32 px-6 bg-white border-t border-gray-50">
        <div class="max-w-7xl mx-auto">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-20 items-start">
                
                <article class="space-y-12" data-aos="fade-up" data-aos-duration="1000">
                    <div class="inline-block">
                        <span class="text-[#7a3f91] text-xs md:text-sm font-bold uppercase tracking-[0.4em]">Our Heritage</span>
                        <div class="w-12 h-1 bg-[#2b0d3e] mt-2"></div>
                    </div>

                    <h3 class="text-4xl md:text-7xl font-black text-[#2b0d3e] leading-[1.1] tracking-tighter uppercase">
                        History & <br><span class="text-[#7a3f91]">Foundation</span>
                    </h3>

                    <div class="space-y-8 text-gray-500 text-lg md:text-xl leading-relaxed">
                        <p class="text-[#2b0d3e] font-bold text-2xl md:text-4xl tracking-tight leading-tight">
                            The Philippine College of Science and Technology (PHILCST) is a private, non-sectarian institution of higher learning.
                        </p>
                        <p>
                            It was established in 1994 by Mrs. Lourdes S. Fernandez as a response to the community’s need for quality education following the 1990 Dagupan earthquake.
                        </p>
                        
                        <div class="p-8 md:p-10 bg-gray-50 rounded-[2.5rem] border-l-[6px] border-[#7a3f91]">
                            <p class="text-[#2b0d3e] italic font-bold text-xl md:text-2xl leading-relaxed">
                                "Since June 1994, PHILCST has expanded its facilities to develop graduates who are globally competitive."
                            </p>
                        </div>
                    </div>
                </article>

                <aside class="grid grid-cols-1 gap-10" data-aos="fade-left" data-aos-duration="1200">
                    <div class="rounded-[3rem] overflow-hidden shadow-xl border border-gray-100">
                        <img src="{{ asset('images/school.jpg') }}" class="w-full h-72 md:h-[450px] object-cover hover:scale-105 transition-transform duration-1000">
                    </div>
                    <div class="rounded-[3rem] overflow-hidden shadow-xl border border-gray-100">
                        <img src="{{ asset('images/school-1.jpg') }}" class="w-full h-64 md:h-[350px] object-cover hover:scale-105 transition-transform duration-1000">
                    </div>
                </aside>

            </div>
        </div>
    </section>

</main>

@include('layouts.footer')

@endsection