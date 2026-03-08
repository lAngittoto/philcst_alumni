{{-- resources/views/organizer/dashboard.blade.php --}}
@extends('layouts.sidebar-organizer')

@section('content')

@php $organizer = auth()->user()->organizer; @endphp

<div class="p-6">
    @if (!$organizer)
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded shadow-md mb-6" role="alert">
            <p class="font-bold flex items-center">
                <i class="fa-solid fa-circle-exclamation mr-2"></i> Error
            </p>
            <p>Organizer record not found. Please contact the administrator.</p>
        </div>
    @else
        {{-- Header Section --}}
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-800">Manage Job Postings</h1>
            <p class="text-gray-600">Post and track job opportunities for the alumni community.</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            {{-- Form Section: New Posting --}}
            <div class="lg:col-span-1">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                    <h2 class="text-xl font-semibold mb-4 flex items-center">
                        <i class="fa-solid fa-plus-circle mr-2 text-blue-600"></i> New Posting
                    </h2>
                    
                    <form action="#" method="POST" class="space-y-4">
                        @csrf
                        {{-- Job Title --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Job Title</label>
                            <input type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="e.g. Software Engineer">
                        </div>

                        {{-- Company Selection --}}
                        <div x-data="{ company: 'philcst' }">
                            <label class="block text-sm font-medium text-gray-700">Company</label>
                            <select x-model="company" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                                <option value="philcst">PhilCST</option>
                                <option value="partner">Partner Companies</option>
                            </select>

                            {{-- Dynamic Location --}}
                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700">Location</label>
                                <template x-if="company === 'philcst'">
                                    <input type="text" value="Old Nalsian Road, Calasiao, Pangasinan" readonly class="mt-1 block w-full bg-gray-50 border-gray-300 rounded-md sm:text-sm text-gray-500">
                                </template>
                                <template x-if="company === 'partner'">
                                    <input type="text" placeholder="Enter Company Location" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                                </template>
                            </div>
                        </div>

                        {{-- Employment Type & Experience --}}
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Employment Type</label>
                                <select class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                                    <option>Full-time</option>
                                    <option>Part-time</option>
                                    <option>Freelance</option>
                                    <option>Contract</option>
                                    <option>Internship</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Experience Level</label>
                                <select class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                                    <option>Entry Level</option>
                                    <option>2-3 Years Experience</option>
                                    <option>No Experience Required</option>
                                </select>
                            </div>
                        </div>

                        {{-- Salary & Deadline --}}
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Salary (Optional)</label>
                                <input type="text" placeholder="e.g. 25k - 30k" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Deadline</label>
                                <input type="date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                            </div>
                        </div>

                        {{-- Description --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Description & Qualifications</label>
                            <textarea rows="4" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="List the requirements..."></textarea>
                        </div>

                        {{-- Buttons --}}
                        <div class="flex justify-end space-x-3 pt-4">
                            <button type="button" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">Cancel</button>
                            <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md hover:bg-blue-700 shadow-sm">Post Job</button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Table Section: Active Postings --}}
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                        <h2 class="text-xl font-semibold text-gray-800">Active Postings</h2>
                        <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded">Live</span>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Job Details</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Deadline</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                {{-- Sample Row --}}
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-bold text-gray-900">Senior Web Developer</div>
                                        <div class="text-sm text-gray-500">PhilCST • Calasiao, Pangasinan</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Full-time</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        Oct 30, 2023
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <button class="text-blue-600 hover:text-blue-900 mr-3"><i class="fa-solid fa-eye"></i> View</button>
                                        <button class="text-indigo-600 hover:text-indigo-900"><i class="fa-solid fa-pen-to-square"></i> Edit</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    @endif
</div>

{{-- Script for simple toggle (AlpineJS is recommended) --}}
<script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>

@endsection