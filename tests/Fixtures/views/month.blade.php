<h1>{{ $month->calendar->name }} {{ $month->year }}-{{ $month->month }}</h1>
<ol>
@foreach ($month->days as $day)
    <li data-date="{{ $day->date }}">{{ $day->label() }}@foreach ($day->occurrences as $o) · {{ $o->name }}@endforeach</li>
@endforeach
</ol>
