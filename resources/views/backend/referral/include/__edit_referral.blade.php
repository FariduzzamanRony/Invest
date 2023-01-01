<div
    class="modal fade"
    id="editReferral"
    tabindex="-1"
    aria-labelledby="addNewLevelModalLabel"
    aria-hidden="true"
>
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content site-table-modal">
            <div class="modal-body popup-body">
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>
                <form action="{{ route('admin.referral.update') }}" method="post">
                    @csrf
                    <input type="hidden" name="id" class="referral-id">
                    <input type="hidden" name="type" class="referral-type">

                    <div class="popup-body-text">
                        <h3 class="title mb-4">{{ __('Edit Level') }}</h3>
                        <div class="site-input-groups">
                            <label for="" class="box-input-label">{{ __('Choose One:') }}</label>
                            <select name="referral_target_id" class="form-select mb-0 target_id" required>
                                <option value="">--{{ __('Select One') }}--</option>
                                @foreach( $targets as $target)
                                    <option value="{{ $target->id }}">{{ $target->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="site-input-groups">
                            <label for="" class="box-input-label">{{ __('Target Amount:') }}</label>
                            <input type="text" name="target_amount" oninput="this.value = validateDouble(this.value)"
                                   class="box-input mb-0 target-amount" placeholder="50" required=""/>
                        </div>
                        <div class="site-input-groups">
                            <label for="" class="box-input-label">{{ __('Bounty:') }}</label>
                            <input type="text" name="bounty" class="box-input mb-0 bounty"
                                   oninput="this.value = validateDouble(this.value)" placeholder="5%" required=""/>
                        </div>
                        <div class="site-input-groups mb-0">
                            <label for="" class="box-input-label">{{ __('Description:') }}</label>
                            <textarea name="description" class="form-textarea description"
                                      placeholder="Description"></textarea>
                        </div>

                        <div class="action-btns">
                            <button type="submit" class="site-btn-sm primary-btn me-2">
                                <i icon-name="check"></i>
                                {{ __('Save Changes') }}
                            </button>
                            <a
                                href="#"
                                class="site-btn-sm red-btn"
                                data-bs-dismiss="modal"
                                aria-label="Close"
                            >
                                <i icon-name="x"></i>
                                {{ __('Close') }}
                            </a>
                        </div>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>
