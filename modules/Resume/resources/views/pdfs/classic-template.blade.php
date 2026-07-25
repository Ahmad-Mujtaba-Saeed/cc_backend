<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $resumeData['candidateName'][0]['firstName'] ?? '' }} {{ $resumeData['candidateName'][0]['familyName'] ?? '' }} - Resume</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@100..900&display=swap" rel="stylesheet">
    <style>
        body { font-family: Inter, Arial, sans-serif; line-height: 1.2; margin: 0; padding: 0; color: #000; }
        .resume { max-width: 8in; margin: 0 auto; padding: 0.4in; }
        .header { margin-bottom: 15px; padding-bottom: 10px; }
        .contact-info { font-size: 14px; margin-bottom: 5px; color: #424242ff; }
        .section { margin-bottom: 15px; font-size: 14px; }
        .section-title { font-size: 15px; font-weight: bold; margin-bottom: 5px; color: rgb(12, 170, 219); }
        .experience-item { margin-bottom: 10px; font-size: 14px; }
        .job-title { font-weight: bold; margin-bottom: 4px; font-size: 14px; color: rgb(12, 170, 219); }
        .company { font-weight: bold; color: rgb(12, 170, 219); }
        .date { font-size: 11px; margin-bottom: 10px; display: block; color: #878787; }
        .responsibilities { margin-top: 5px; padding-left: 15px; }
        .responsibilities li { margin-bottom: 2px; font-size: 14px; }
        .page-break { page-break-before: always; margin-top: 40px; }
        strong { font-weight: 500; }
    </style>
</head>
<body>
    <div class="resume">
        <!-- Header with Contact Info -->
        <div class="header">
            @if(!empty($resumeData['profilePic']))
                <img src="{{ $resumeData['profilePic'] }}" alt="Profile" class="profile-image">
            @endif
            <div class="contact-info">
                {{ $resumeData['candidateName'][0]['firstName'] ?? '' }}{{ $resumeData['candidateName'][0]['familyName'] ? ' ' . $resumeData['candidateName'][0]['familyName'] : '' }}
                @if(!empty($resumeData['email'][0])) | {{ $resumeData['email'][0] }} @endif
                @if(!empty($resumeData['phoneNumber'][0]['formattedNumber'])) | {{ $resumeData['phoneNumber'][0]['formattedNumber'] }} @endif
                @if(!empty($resumeData['location']['formatted'])) | {{ $resumeData['location']['formatted'] }} @endif
                @if(!empty($resumeData['location']['city'])){{ $resumeData['location']['city'] }}@endif
                @if(!empty($resumeData['location']['postCode'])) ({{ $resumeData['location']['postCode'] }}) @endif
                @if(!empty($resumeData['socialLinks']['github']))<br>Github: {{ $resumeData['socialLinks']['github'] }} @endif
                @if(!empty($resumeData['socialLinks']['linkedin']))<br>LinkedIn: {{ $resumeData['socialLinks']['linkedin'] }} @endif
                @if(!empty($resumeData['socialLinks']['website']))<br>Website: {{ $resumeData['socialLinks']['website'] }} @endif
            </div>
        </div>

        <!-- Headline -->
        @if(!empty($resumeData['headline']))
        <div class="section">
            <h2 class="section-title">Candidate Headline</h2>
            <p class="headline-text">{{ $resumeData['headline'] }}</p>
        </div>
        @endif

        <!-- Profile -->
        @if(!empty($resumeData['summary']['paragraph']) && empty($resumeData['profileDisabled']))
        <div class="section">
            <h2 class="section-title">{{ $resumeData['profileTitle'] ?? 'Profile' }}</h2>
            <div class="profile-text">{{ $resumeData['summary']['paragraph'] }}</div>
        </div>
        @endif

        <div class="two-column">
            <!-- Left Column -->
            <div class="left-column">
                <!-- Skills -->
                @if(!empty($resumeData['skill']) && empty($resumeData['skillsDisabled']))
                <div class="section">
                    <h2 class="section-title">{{ $resumeData['skillsTitle'] ?? 'Key Skills' }}</h2>
                    <ul class="bullet-list">
                        @foreach($resumeData['skill'] as $skill)
                            @if(!isset($skill['selected']) || $skill['selected'])
                                <li>{{ $skill['name'] ?? $skill }}</li>
                            @endif
                        @endforeach
                    </ul>
                </div>
                @endif

            <!-- Right Column -->
            <div class="right-column">
                <!-- Experience -->
                @if(!empty($resumeData['workExperience']) && empty($resumeData['employmentDisabled']))
                <div class="section">
                    <h2 class="section-title">{{ $resumeData['employmentTitle'] ?? 'Experience' }}</h2>
                    @foreach($resumeData['workExperience'] as $job)
                    <div class="experience-item">
                        <h3 class="job-title">
                            {{ $job['workExperienceJobTitle'] ?? '' }} | 
                            <span class="company">{{ $job['workExperienceOrganization'] ?? '' }}</span>
                        </h3>
                        <div class="date">
                            {{ $job['workExperienceDates']['start']['date'] ?? '' }} - 
                            {{ $job['workExperienceDates']['end']['date'] ?? 'Present' }}
                        </div>
                        @if(!empty($job['workExperienceDescription']))
                        <p class="profile-text">{{ $job['workExperienceDescription'] }}</p>
                        @endif
                        @if(!empty($job['highlights']['items']))
                        <ul class="bullet-list">
                            @foreach($job['highlights']['items'] as $point)
                                <li>{{ $point['bullet'] }}</li>
                            @endforeach
                        </ul>
                        @endif
                    </div>
                    @endforeach
                </div>
                @endif

                <!-- Education -->
                @if(!empty($resumeData['education']) && empty($resumeData['educationDisabled']))
                <div class="section">
                    <h2 class="section-title">{{ $resumeData['educationTitle'] ?? 'Education' }}</h2>
                    @foreach($resumeData['education'] as $edu)
                    <div class="experience-item">
                        <h3 class="job-title">
                            {{ $edu['educationLevel']['label'] ?? '' }} | 
                            <span class="company">{{ $edu['educationOrganization'] ?? '' }}</span>
                        </h3>
                        <div class="date">
                            {{ $edu['educationDates']['start']['date'] ?? '' }} - 
                            {{ $edu['educationDates']['end']['date'] ?? '' }}
                        </div>
                        @if(!empty($edu['educationMajor']) && is_array($edu['educationMajor']) && count($edu['educationMajor']) > 0)
                        <div class="education-major">
                            <strong>Subjects:</strong> 
                            {{ implode(', ', $edu['educationMajor']) }}
                        </div>
                        @endif
                        @if(!empty($edu['achievedGrade']))
                        <div class="education-grade">
                            <strong>Grade:</strong> {{ $edu['achievedGrade'] }}
                        </div>
                        @endif
                        @if(!empty($edu['educationDescription']))
                        <p class="profile-text">{{ $edu['educationDescription'] }}</p>
                        @endif
                    </div>
                    @endforeach
                </div>
                @endif
            </div>

            <!-- Hobbies -->
            @if(!empty($resumeData['hobbies']) && empty($resumeData['hobbiesDisabled']))
            <div class="left-column">
                <div class="section">
                    <h2 class="section-title">{{ $resumeData['hobbiesTitle'] ?? 'Hobbies' }}</h2>
                    <ul class="bullet-list">
                        @foreach($resumeData['hobbies'] as $hobby)
                            <li>{{ $hobby }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            @endif

            <!-- Languages -->
            @if(!empty($resumeData['languages']) && empty($resumeData['languagesDisabled']))
            <div class="left-column">
                <div class="section">
                    <h2 class="section-title">{{ $resumeData['languagesTitle'] ?? 'Languages' }}</h2>
                    <ul class="bullet-list">
                        @foreach($resumeData['languages'] as $lang)
                            <li>{{ $lang['name'] }} ({{ $lang['level'] ?? 'Fluent' }})</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            @endif
        </div>

        <!-- Custom Sections -->
        @if(!empty($resumeData['customSections']))
            @foreach($resumeData['customSections'] as $section)
                @if(!empty($section['title']) && !empty($section['content']))
                <div class="section">
                    <h2 class="section-title">{{ $section['title'] }}</h2>
                    <div class="profile-text">
                        {!! $section['content'] !!}
                    </div>
                </div>
                @endif
            @endforeach
        @endif
    </div>
</body>
</html>
