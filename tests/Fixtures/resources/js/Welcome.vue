<script setup>
const regular = $t('frontend.Regular translation');
const short = $t('frontend.Works.');
const grouped = $t('frontend.grouped.Works.');
const jsonOne = $t('This ends up in JSON');
const jsonTwo = $t('This ends up in JSON too.');
const jsonMultiline = $t(`This is
multiline JSON`);
const parametrized = $t('frontend.Today is :date, :time.');
const multiline = $t(`frontend.Multiline line one
line two
line three`);
const labelOne = trans('frontend.dynamicLabels.labels.Dynamic Label 1');
const labelTwo = trans('frontend.dynamicLabels.labels.Dynamic Label 2');
const dynamicValue = $t('frontend.dynamicLabels.values.' + key);
const dynamicValueTwo = $t(`frontend.dynamicLabels2.values.${key}`);
</script>
