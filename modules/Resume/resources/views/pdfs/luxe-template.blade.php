<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $resumeData['candidateName'][0]['firstName'] ?? '' }} {{ $resumeData['candidateName'][0]['familyName'] ?? '' }} — Resume</title>
  <style>
    body {
      font-family: 'Arial', sans-serif;
      margin: 0;
      padding: 0;
      background: #f7f7f7;
      color: #000;
    }
    .resume {
      background: #fff;
      width: 800px;
      margin: 40px auto;
      padding: 40px 50px;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
      box-sizing: border-box;
      overflow-wrap: break-word;
      word-wrap: break-word;
      word-break: break-word;
    }
    .headline {
      font-size: 15px;
      color: #7a1e37;
      font-weight: bold;
      margin-top: 5px;
      word-break: break-word;
    }
    .header {
      display: flex;
      align-items: center;
      margin-bottom: 30px;
    }
    .header-bar {
      background-color: #7a1e37;
      width: 60px;
      height: 60px;
      margin-right: 15px;
      flex-shrink: 0;
    }
    .name {
      font-size: 28px;
      color: #7a1e37;
      font-weight: bold;
      margin: 0;
      word-break: break-word;
    }
    .section-title {
      font-size: 18px;
      border-bottom: 1px solid #ccc;
      padding-bottom: 5px;
      margin-top: 30px;
      color: #000;
      word-break: break-word;
    }
    .personal-details {
      margin-top: 10px;
      line-height: 1.6;
    }
    .personal-detail-item {
      display: flex;
      margin-bottom: 5px;
      flex-wrap: wrap;
    }
    .label {
      width: 130px;
      font-weight: bold;
      color: #7a1e37;
      flex-shrink: 0;
    }
    .detail-content {
      flex: 1;
      min-width: 200px;
      word-break: break-word;
      max-width: calc(100% - 130px);
    }
    .summary {
      margin-top: 10px;
      line-height: 1.6;
      word-break: break-word;
      text-align: justify;
    }
    .section-content {
      margin-top: 10px;
      line-height: 1.6;
      word-break: break-word;
    }
    .work-experience {
      margin-top: 10px;
    }
    .job {
      margin-bottom: 20px;
      page-break-inside: avoid;
    }
    .job-date {
      color: #7a1e37;
      font-weight: bold;
      word-break: break-word;
    }
    .job-title {
      font-weight: bold;
      word-break: break-word;
      margin: 3px 0;
    }
    .job-company {
      font-weight: bold;
      color: #000;
      word-break: break-word;
      margin: 3px 0 8px 0;
    }
    .bullet-list {
      margin: 8px 0 0 0;
      padding-left: 20px;
      word-break: break-word;
    }
    .bullet-item {
      margin-bottom: 8px;
      word-break: break-word;
      line-height: 1.4;
      text-align: justify;
    }
    .sidebar-list {
      list-style: none;
      padding: 0;
      margin: 10px 0 0 0;
    }
    .sidebar-list-item {
      margin-bottom: 5px;
      word-break: break-word;
    }
    .pagecontentfull {
      margin-bottom: 5px;
      word-break: break-word;
    }
    .education-grade {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
    }
    .grade-label {
      font-size: 17px;
      font-weight: 700;
    }
    
    /* Text content containers */
    p {
      word-break: break-word;
      margin: 8px 0;
      line-height: 1.5;
      text-align: justify;
    }
    
    h5 {
      word-break: break-word;
      margin: 15px 0 8px 0;
      font-size: 14px;
      color: #7a1e37;
    }

    /* Job description specific styling */
    .job-description {
      word-break: break-word;
      line-height: 1.5;
      text-align: justify;
      margin: 8px 0;
    }

    /* Print Styles */
    @media print {
      @page {
        margin: 0.5in;
      }
      body {
        background: #fff !important;
        margin: 0;
        padding: 0;
      }
      .resume {
        margin: 0 !important;
        padding: 0.4in !important;
        width: 100% !important;
        box-shadow: none !important;
        max-width: none;
      }
      .section-title, .job, .bullet-item {
        page-break-inside: avoid;
      }
      .bullet-item {
        margin-bottom: 6px;
      }
    }

    /* Force text wrapping for long content */
    .text-container {
      max-width: 100%;
      word-break: break-word;
      overflow-wrap: break-word;
      hyphens: auto;
    }
  </style>
</head>

<body>
  <div class="resume">
    @if(!($resumeData['personalDisabled'] ?? false))
      <!-- Header with Name and Headline -->
      <div class="header">
        <div class="header-bar"></div>
        <div class="text-container">
          <h1 class="name">
            {{ $resumeData['candidateName'][0]['firstName'] ?? '' }} {{ $resumeData['candidateName'][0]['familyName'] ?? '' }}
          </h1>
          <p class="headline">{{ $resumeData['headline'] ?? 'Professional title' }}</p>
        </div>
      </div>

      <!-- Personal Details -->
      <h2 class="section-title">{{ $resumeData['personalTitle'] ?? 'Personal Details' }}</h2>
      <div class="personal-details">
        <div class="personal-detail-item">
          <div class="label">Name</div>
          <div class="detail-content text-container">
            {{ $resumeData['candidateName'][0]['firstName'] ?? '' }} {{ $resumeData['candidateName'][0]['familyName'] ?? '' }}
          </div>
        </div>
        @if(!empty($resumeData['email'][0]))
        <div class="personal-detail-item">
          <div class="label">Email address</div>
          <div class="detail-content text-container">{{ $resumeData['email'][0] }}</div>
        </div>
        @endif
        @if(!empty($resumeData['phoneNumber'][0]['formattedNumber']))
        <div class="personal-detail-item">
          <div class="label">Phone number</div>
          <div class="detail-content text-container">{{ $resumeData['phoneNumber'][0]['formattedNumber'] }}</div>
        </div>
        @endif
        @if(!empty($resumeData['location']['formatted']))
        <div class="personal-detail-item">
          <div class="label">Address</div>
          <div class="detail-content text-container">{{ $resumeData['location']['formatted'] }}</div>
        </div>
        @endif
        @if(!empty($resumeData['location']['city']))
        <div class="personal-detail-item">
          <div class="label">City</div>
          <div class="detail-content text-container">{{ $resumeData['location']['city'] }}</div>
        </div>
        @endif
        @if(!empty($resumeData['location']['postCode']))
        <div class="personal-detail-item">
          <div class="label">Postal Code</div>
          <div class="detail-content text-container">{{ $resumeData['location']['postCode'] }}</div>
        </div>
        @endif
        @if(!empty($resumeData['socialLinks']['github']))
        <div class="personal-detail-item">
          <div class="label">GitHub</div>
          <div class="detail-content text-container">{{ $resumeData['socialLinks']['github'] }}</div>
        </div>
        @endif
        @if(!empty($resumeData['socialLinks']['linkedin']))
        <div class="personal-detail-item">
          <div class="label">LinkedIn</div>
          <div class="detail-content text-container">{{ $resumeData['socialLinks']['linkedin'] }}</div>
        </div>
        @endif
        @if(!empty($resumeData['socialLinks']['website']))
        <div class="personal-detail-item">
          <div class="label">Website</div>
          <div class="detail-content text-container">{{ $resumeData['socialLinks']['website'] }}</div>
        </div>
        @endif
      </div>

      <!-- Summary -->
      @if(!empty($resumeData['summary']['paragraph']))
        <h2 class="section-title">Summary</h2>
        <div class="summary text-container">
          {{ $resumeData['summary']['paragraph'] }}
        </div>
      @endif
    @endif

    <!-- Work Experience -->
    @if(!empty($resumeData['workExperience']) && !($resumeData['employmentDisabled'] ?? false))
      <h2 class="section-title">{{ $resumeData['employmentTitle'] ?? 'Employment' }}</h2>
      <div class="work-experience">
        @foreach($resumeData['workExperience'] as $job)
          <div class="job">
            <div class="job-date text-container">
              {{ $job['workExperienceDates']['start']['date'] ?? '' }} - {{ $job['workExperienceDates']['end']['date'] ?? 'Present' }}
            </div>
            <div class="job-title text-container">{{ $job['workExperienceJobTitle'] ?? '' }}</div>
            <div class="job-company text-container">{{ $job['workExperienceOrganization'] ?? '' }}</div>
            @if(!empty($job['workExperienceDescription']))
              <p class="job-description text-container">{{ $job['workExperienceDescription'] }}</p>
            @endif
            @if(!empty($job['highlights']['items']))
              <h5>Key Achievements</h5>
              <ul class="bullet-list">
                @foreach($job['highlights']['items'] as $point)
                  <li class="bullet-item text-container">{{ $point['bullet'] }}</li>
                @endforeach
              </ul>
            @endif
          </div>
        @endforeach
      </div>
    @endif

    <!-- Education -->
    @if(!empty($resumeData['education']) && !($resumeData['educationDisabled'] ?? false))
      <h2 class="section-title">{{ $resumeData['educationTitle'] ?? 'Education' }}</h2>
      <div class="section-content">
        @foreach($resumeData['education'] as $edu)
          <div class="job">
            <div class="pagecontentfull text-container">
              <strong>{{ $edu['educationDates']['start']['date'] ?? '' }} - {{ $edu['educationDates']['end']['date'] ?? '' }}</strong>
            </div>
            <div class="pagecontentfull text-container">{{ $edu['educationLevel']['label'] ?? '' }}</div>
            @if(!empty($edu['achievedGrade']))
              <div class="pagecontentfull text-container">
                <div class="education-grade">
                  <span class="grade-label">Grade:</span>
                  {{ $edu['achievedGrade'] }}
                </div>
              </div>
            @endif
            <div class="pagecontentfull text-container">{{ $edu['educationOrganization'] ?? '' }}</div>
            @if(!empty($edu['educationDescription']))
              <ul class="bullet-list">
                @foreach(explode("\n", $edu['educationDescription']) as $point)
                  @if(trim($point) !== '')
                    <li class="bullet-item text-container">{{ $point }}</li>
                  @endif
                @endforeach
              </ul>
            @endif
          </div>
        @endforeach
      </div>
    @endif

    <!-- Skills -->
    @if(!empty($resumeData['skill']) && !($resumeData['skillsDisabled'] ?? false))
      <h2 class="section-title">{{ $resumeData['skillsTitle'] ?? 'Skills' }}</h2>
      <ul class="sidebar-list">
        @foreach($resumeData['skill'] as $skill)
          @if((is_array($skill) && isset($skill['selected']) && $skill['selected']) || is_string($skill))
            <li class="sidebar-list-item text-container">
              {{ is_array($skill) ? ($skill['name'] ?? $skill) : $skill }}
            </li>
          @endif
        @endforeach
      </ul>
    @endif

    <!-- Languages -->
    @if(!empty($resumeData['languages']) && !($resumeData['languagesDisabled'] ?? false))
      <h2 class="section-title">{{ $resumeData['languagesTitle'] ?? 'Languages' }}</h2>
      <ul class="sidebar-list">
        @foreach($resumeData['languages'] as $language)
          <li class="sidebar-list-item text-container">
            {{ $language['name'] ?? '' }} 
            @if(!empty($language['level']))
              ({{ $language['level'] }})
            @endif
          </li>
        @endforeach
      </ul>
    @endif

    <!-- Hobbies -->
    @if(!empty($resumeData['hobbies']) && !($resumeData['hobbiesDisabled'] ?? false))
      <h2 class="section-title">{{ $resumeData['hobbiesTitle'] ?? 'Hobbies' }}</h2>
      <ul class="sidebar-list">
        @foreach($resumeData['hobbies'] as $hobby)
          <li class="sidebar-list-item text-container">{{ $hobby }}</li>
        @endforeach
      </ul>
    @endif

    <!-- Custom Sections -->
    @if(!empty($resumeData['customSections']))
      @foreach($resumeData['customSections'] as $section)
        <div>
          <h2 class="section-title">{{ $section['title'] ?? 'Additional Information' }}</h2>
          <div class="section-content text-container">
            {!! $section['content'] ?? '' !!}
          </div>
        </div>
      @endforeach
    @endif
  </div>
</body>
</html>