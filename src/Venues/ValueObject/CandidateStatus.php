<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * A photo candidate is a reference to a picture on somebody's website, never a licence to show it (D16). The importer
 * writes `Candidate` only; the claim flow (G6) moves one to `Approved` when the claimant ticks it and gives the
 * rights declaration, or to `Rejected`.
 */
enum CandidateStatus: string
{
    case Candidate = 'candidate';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
